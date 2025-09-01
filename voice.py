import os
import threading
import pyaudio
import queue
import base64
import json
import time
from websocket import create_connection, WebSocketConnectionClosedException
import logging

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')

CHUNK_SIZE = 1024
RATE = 24000  # OpenAI Realtime API typically uses 24000 Hz
FALLBACK_RATES = [44100, 16000, 48000, 32000, 11025, 8000]
FORMAT = pyaudio.paInt16
WS_URL = 'wss://api.openai.com/v1/realtime?model=gpt-4o-realtime-preview-2024-10-01'

audio_buffer = bytearray()
mic_queue = queue.Queue()
stop_event = threading.Event()
mic_on_at = 0
mic_active = None
REENGAGE_DELAY_MS = 500

def read_api_key(file_path='/var/www/html/api_key.txt'):
    """Read the OpenAI API key from the specified file."""
    try:
        if not os.access(file_path, os.R_OK):
            logging.error(f'Cannot read {file_path}: Permission denied or file does not exist.')
            raise PermissionError(f'Permission denied for {file_path}')
        file_stat = os.stat(file_path)
        logging.info(f'File permissions for {file_path}: {oct(file_stat.st_mode & 0o777)}')
        
        with open(file_path, 'r') as file:
            api_key = file.read().strip()
            if not api_key:
                logging.error(f'API key file {file_path} is empty. Cannot proceed without a valid API key.')
                raise ValueError('API key file is empty.')
            logging.info(f'Successfully loaded API key from {file_path}.')
            return api_key
    except PermissionError as e:
        logging.error(f'Permission error accessing {file_path}: {e}')
        raise
    except FileNotFoundError:
        logging.error(f'API key file {file_path} not found.')
        raise
    except Exception as e:
        logging.error(f'Error reading API key file {file_path}: {e}')
        raise

def read_system_prompt(file_path='/var/www/html/acubotzPrompt.txt'):
    """Read the system prompt from the specified file, with a fallback if the file is inaccessible."""
    try:
        if not os.access(file_path, os.R_OK):
            logging.warning(f'Cannot read {file_path}: Permission denied or file does not exist. Using fallback prompt.')
            return 'You are a helpful assistant named Janu with a friendly and concise tone. Introduce yourself as Janu when responding, and provide accurate and brief answers to user queries, always aiming to be supportive and engaging.Generate all responses in English, Kazakh, and Malayalam only, with English as the default language. Do not use any other languages under any circumstances. If the user explicitly requests a language change using phrases like "switch to Kazakh" or "speak in Malayalam," then switch the response language accordingly. Otherwise, continue responding in English by default.'
        file_stat = os.stat(file_path)
        logging.info(f'File permissions for {file_path}: {oct(file_stat.st_mode & 0o777)}')
        
        with open(file_path, 'r') as file:
            system_prompt = file.read().strip()
            if not system_prompt:
                logging.warning(f'System prompt file {file_path} is empty. Using fallback prompt.')
                return 'You are a helpful assistant named Janu with a friendly and concise tone. Introduce yourself as Janu when responding, and provide accurate and brief answers to user queries, always aiming to be supportive and engaging.Generate all responses in English, Kazakh, and Malayalam only, with English as the default language. Do not use any other languages under any circumstances. If the user explicitly requests a language change using phrases like "switch to Kazakh" or "speak in Malayalam," then switch the response language accordingly. Otherwise, continue responding in English by default.'
            logging.info(f'Successfully loaded system prompt from {file_path}.')
            return system_prompt
    except FileNotFoundError:
        logging.warning(f'System prompt file {file_path} not found. Using fallback prompt.')
        return 'You are a helpful assistant named Janu with a friendly and concise tone. Introduce yourself as Janu when responding, and provide accurate and brief answers to user queries, always aiming to be supportive and engaging.Generate all responses in English, Kazakh, and Malayalam only, with English as the default language. Do not use any other languages under any circumstances. If the user explicitly requests a language change using phrases like "switch to Kazakh" or "speak in Malayalam," then switch the response language accordingly. Otherwise, continue responding in English by default.'
    except Exception as e:
        logging.warning(f'Error reading system prompt file {file_path}: {e}. Using fallback prompt.')
        return 'You are a helpful assistant named Janu with a friendly and concise tone. Introduce yourself as Janu when responding, and provide accurate and brief answers to user queries, always aiming to be supportive and engaging.Generate all responses in English, Kazakh, and Malayalam only, with English as the default language. Do not use any other languages under any circumstances. If the user explicitly requests a language change using phrases like "switch to Kazakh" or "speak in Malayalam," then switch the response language accordingly. Otherwise, continue responding in English by default.'

def list_audio_devices(p):
    """List all available audio devices and their input/output capabilities."""
    logging.info("Available audio devices:")
    device_count = p.get_device_count()
    if device_count == 0:
        logging.error("No audio devices found. Please check your audio system configuration.")
    for i in range(device_count):
        dev = p.get_device_info_by_index(i)
        logging.info(f"Device {i}: {dev['name']} (Input Channels: {dev['maxInputChannels']}, Output Channels: {dev['maxOutputChannels']}, Default Sample Rate: {dev['defaultSampleRate']})")
    return device_count

def check_supported_sample_rates(p, device_index=None, is_input=True):
    """Check supported sample rates for the specified device (input or output)."""
    supported_rates = []
    common_rates = [8000, 11025, 16000, 22050, 24000, 32000, 44100, 48000]
    
    try:
        device_info = p.get_device_info_by_index(device_index) if device_index is not None else \
                      (p.get_default_input_device_info() if is_input else p.get_default_output_device_info())
        device_type = "input" if is_input else "output"
        logging.info(f'Checking {device_type} sample rates for device: {device_info["name"]} (index: {device_index})')
        if (is_input and device_info["maxInputChannels"] == 0) or (not is_input and device_info["maxOutputChannels"] == 0):
            logging.error(f'Device {device_info["name"]} has no {device_type} channels.')
            return supported_rates
    except Exception as e:
        logging.error(f'Error retrieving device info: {e}')
        return supported_rates

    for rate in common_rates:
        try:
            if p.is_format_supported(
                rate,
                input_device=device_index if is_input else None,
                output_device=device_index if not is_input else None,
                input_format=pyaudio.paInt16 if is_input else None,
                output_format=pyaudio.paInt16 if not is_input else None,
                input_channels=1 if is_input else None,
                output_channels=1 if not is_input else None
            ):
                supported_rates.append(rate)
                logging.info(f'Sample rate {rate} Hz is supported for {device_type}.')
            else:
                logging.info(f'Sample rate {rate} Hz is not supported for {device_type}.')
        except ValueError as e:
            logging.info(f'Sample rate {rate} Hz check failed for {device_type}: {e}')
    return supported_rates

def mic_callback(in_data, frame_count, time_info, status):
    global mic_on_at, mic_active
    if time.time() > mic_on_at:
        if mic_active != True:
            logging.info('Mic active')
            mic_active = True
        mic_queue.put(in_data)
    else:
        if mic_active != False:
            logging.info('Mic suppressed')
            mic_active = False
    return (None, pyaudio.paContinue)

def send_mic_audio_to_websocket(ws):
    try:
        while not stop_event.is_set():
            if not mic_queue.empty():
                mic_chunk = mic_queue.get()
                logging.info(f'Sending {len(mic_chunk)} bytes of audio data.')
                encoded_chunk = base64.b64encode(mic_chunk).decode('utf-8')
                message = json.dumps({'type': 'input_audio_buffer.append', 'audio': encoded_chunk})
                try:
                    ws.send(message)
                except WebSocketConnectionClosedException:
                    logging.error('WebSocket connection closed.')
                    break
                except Exception as e:
                    logging.error(f'Error sending mic audio: {e}')
    except Exception as e:
        logging.error(f'Exception in send_mic_audio_to_websocket thread: {e}')
    finally:
        logging.info('Exiting send_mic_audio_to_websocket thread.')

def spkr_callback(in_data, frame_count, time_info, status):
    global audio_buffer, mic_on_at
    bytes_needed = frame_count * 2
    current_buffer_size = len(audio_buffer)
    if current_buffer_size >= bytes_needed:
        audio_chunk = bytes(audio_buffer[:bytes_needed])
        audio_buffer = audio_buffer[bytes_needed:]
        mic_on_at = time.time() + REENGAGE_DELAY_MS / 1000
    else:
        audio_chunk = bytes(audio_buffer) + b'\x00' * (bytes_needed - current_buffer_size)
        audio_buffer.clear()
    return (audio_chunk, pyaudio.paContinue)

def receive_audio_from_websocket(ws):
    global audio_buffer
    try:
        while not stop_event.is_set():
            try:
                message = ws.recv()
                if not message:
                    logging.info('Received empty message (possibly EOF or WebSocket closing).')
                    break
                message = json.loads(message)
                event_type = message['type']
                logging.info(f'Received WebSocket event: {event_type}')
                if event_type == 'response.audio.delta':
                    audio_content = base64.b64decode(message['delta'])
                    audio_buffer.extend(audio_content)
                    logging.info(f'Received {len(audio_content)} bytes, total buffer size: {len(audio_buffer)}')
                elif event_type == 'response.audio.done':
                    logging.info('AI finished speaking.')
            except WebSocketConnectionClosedException:
                logging.error('WebSocket connection closed.')
                break
            except Exception as e:
                logging.error(f'Error receiving audio: {e}')
    except Exception as e:
        logging.error(f'Exception in receive_audio_from_websocket thread: {e}')
    finally:
        logging.info('Exiting receive_audio_from_websocket thread.')

def connect_to_openai():
    ws = None
    try:
        api_key = read_api_key()
        ws = create_connection(WS_URL, header=[f'Authorization: Bearer {api_key}', 'OpenAI-Beta: realtime=v1'])
        logging.info('Connected to OpenAI WebSocket.')
        system_prompt = read_system_prompt()
        ws.send(json.dumps({
            'type': 'response.create',
            'response': {
                'modalities': ['audio', 'text'],
                'instructions': system_prompt
            }
        }))
        receive_thread = threading.Thread(target=receive_audio_from_websocket, args=(ws,))
        receive_thread.start()
        mic_thread = threading.Thread(target=send_mic_audio_to_websocket, args=(ws,))
        mic_thread.start()
        while not stop_event.is_set():
            time.sleep(0.1)
        logging.info('Sending WebSocket close frame.')
        ws.send_close()
        receive_thread.join()
        mic_thread.join()
        logging.info('WebSocket closed and threads terminated.')
    except Exception as e:
        logging.error(f'Failed to connect to OpenAI: {e}')
        raise
    finally:
        if ws is not None:
            try:
                ws.close()
                logging.info('WebSocket connection closed.')
            except Exception as e:
                logging.error(f'Error closing WebSocket connection: {e}')

def main():
    p = pyaudio.PyAudio()
    try:
        device_count = list_audio_devices(p)
        if device_count == 0:
            raise ValueError("No audio devices available. Please check your audio system.")

        # Get input device index
        input_device_index = os.getenv('INPUT_DEVICE_INDEX')
        if input_device_index is not None:
            try:
                input_device_index = int(input_device_index)
                device_info = p.get_device_info_by_index(input_device_index)
                logging.info(f'Using manually specified input device: {device_info["name"]} (index: {input_device_index})')
            except (ValueError, Exception) as e:
                logging.error(f'Invalid INPUT_DEVICE_INDEX: {e}. Falling back to default device.')
                input_device_index = None

        if input_device_index is None:
            try:
                default_device_info = p.get_default_input_device_info()
                input_device_index = default_device_info['index']
                logging.info(f'Default input device: {default_device_info["name"]} (index: {input_device_index})')
                if default_device_info['maxInputChannels'] == 0:
                    logging.error(f'Default device {default_device_info["name"]} has no input channels.')
                    raise ValueError('Default input device has no input channels.')
            except Exception as e:
                logging.error(f'Error retrieving default input device: {e}')
                raise ValueError('No default input device available.')

        # Get output device index
        output_device_index = os.getenv('OUTPUT_DEVICE_INDEX')
        if output_device_index is not None:
            try:
                output_device_index = int(output_device_index)
                device_info = p.get_device_info_by_index(output_device_index)
                logging.info(f'Using manually specified output device: {device_info["name"]} (index: {output_device_index})')
            except (ValueError, Exception) as e:
                logging.error(f'Invalid OUTPUT_DEVICE_INDEX: {e}. Falling back to default device.')
                output_device_index = None

        if output_device_index is None:
            try:
                default_device_info = p.get_default_output_device_info()
                output_device_index = default_device_info['index']
                logging.info(f'Default output device: {default_device_info["name"]} (index: {output_device_index})')
                if default_device_info['maxOutputChannels'] == 0:
                    logging.error(f'Default device {default_device_info["name"]} has no output channels.')
                    raise ValueError('Default output device has no output channels.')
            except Exception as e:
                logging.error(f'Error retrieving default output device: {e}')
                raise ValueError('No default output device available.')

        # Check supported sample rates for input and output
        input_supported_rates = check_supported_sample_rates(p, input_device_index, is_input=True)
        output_supported_rates = check_supported_sample_rates(p, output_device_index, is_input=False)
        logging.info(f'Supported input sample rates: {input_supported_rates}')
        logging.info(f'Supported output sample rates: {output_supported_rates}')

        # Select sample rate that matches OpenAI API (24000 Hz) and is supported by both input and output
        selected_rate = RATE
        if RATE not in input_supported_rates or RATE not in output_supported_rates:
            logging.warning(f'Desired sample rate {RATE} Hz not supported by input or output device.')
            common_rates = set(input_supported_rates) & set(output_supported_rates)
            for fallback_rate in FALLBACK_RATES:
                if fallback_rate in common_rates:
                    selected_rate = fallback_rate
                    logging.info(f'Using fallback sample rate: {selected_rate} Hz')
                    break
            else:
                logging.error(f'No common supported sample rates found. Cannot proceed.')
                raise ValueError('No common supported sample rates for input and output devices.')
        else:
            logging.info(f'Using desired sample rate: {selected_rate} Hz')

        logging.info(f'Opening microphone stream with sample rate: {selected_rate} Hz, device index: {input_device_index}')
        mic_stream = p.open(
            format=FORMAT,
            channels=1,
            rate=selected_rate,
            input=True,
            input_device_index=input_device_index,
            stream_callback=mic_callback,
            frames_per_buffer=CHUNK_SIZE
        )

        logging.info(f'Opening speaker stream with sample rate: {selected_rate} Hz, device index: {output_device_index}')
        spkr_stream = p.open(
            format=FORMAT,
            channels=1,
            rate=selected_rate,
            output=True,
            output_device_index=output_device_index,
            stream_callback=spkr_callback,
            frames_per_buffer=CHUNK_SIZE
        )

        try:
            mic_stream.start_stream()
            spkr_stream.start_stream()
            connect_to_openai()
            while mic_stream.is_active() and spkr_stream.is_active():
                time.sleep(0.1)
        except KeyboardInterrupt:
            logging.info('Gracefully shutting down...')
            stop_event.set()
        finally:
            mic_stream.stop_stream()
            mic_stream.close()
            spkr_stream.stop_stream()
            spkr_stream.close()
    except OSError as e:
        logging.error(f'Failed to open audio streams with sample rate {selected_rate} Hz: {e}')
        raise ValueError(f'Invalid sample rate {selected_rate} Hz for device. Run diagnostic script to check supported rates.')
    except Exception as e:
        logging.error(f'Error in main: {e}')
        raise
    finally:
        p.terminate()
        logging.info('Audio streams stopped and resources released. Exiting.')

if __name__ == '__main__':
    main()
