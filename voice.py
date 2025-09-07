import os
os.system('cls' if os.name == 'nt' else 'clear')

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
RATE = 24000  # Desired sample rate
FALLBACK_RATES = [44100, 16000, 48000, 32000, 11025, 8000]  # Expanded fallback rates
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
        # Check file permissions
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
        # Check file permissions
        if not os.access(file_path, os.R_OK):
            logging.warning(f'Cannot read {file_path}: Permission denied or file does not exist. Using fallback prompt.')
            return 'You are a helpful assistant named Janu with a friendly and concise tone. Introduce yourself as Janu when responding, and provide accurate and brief answers to user queries,   always aiming to be supportive and engaging.Generate all responses in English, Kazakh, and Malayalam only, with English as the default language. Do not use any other languages under any circumstances. If the user explicitly requests a language change using phrases like "switch to Kazakh" or "speak in Malayalam," then switch the response language accordingly. Otherwise, continue responding in English by default.'
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

def check_supported_sample_rates(p, device_index=None):
    """Check supported sample rates for the specified input device."""
    supported_rates = []
    common_rates = [8000, 11025, 16000, 22050, 24000, 32000, 44100, 48000]
    
    try:
        device_info = p.get_device_info_by_index(device_index) if device_index is not None else p.get_default_input_device_info()
        logging.info(f'Checking sample rates for device: {device_info["name"]} (index: {device_index}, maxInputChannels: {device_info["maxInputChannels"]})')
        if device_info["maxInputChannels"] == 0:
            logging.error(f'Device {device_info["name"]} has no input channels. It cannot be used for recording.')
            return supported_rates
    except Exception as e:
        logging.error(f'Error retrieving device info: {e}')
        return supported_rates

    for rate in common_rates:
        try:
            if p.is_format_supported(
                rate,
                input_device=device_index,
                input_format=pyaudio.paInt16,
                input_channels=1
            ):
                supported_rates.append(rate)
                logging.info(f'Sample rate {rate} Hz is supported.')
            else:
                logging.info(f'Sample rate {rate} Hz is not supported.')
        except ValueError as e:
            logging.info(f'Sample rate {rate} Hz check failed: {e}')
    return supported_rates

def mic_callback(in_data, frame_count, time_info, status):
    global mic_on_at, mic_active

    if time.time() > mic_on_at:
        if mic_active != True:
            logging.info('????? Mic active')
            mic_active = True
        mic_queue.put(in_data)
    else:
        if mic_active != False:
            logging.info('????? Mic suppressed')
            mic_active = False

    return (None, pyaudio.paContinue)

def send_mic_audio_to_websocket(ws):
    try:
        while not stop_event.is_set():
            if not mic_queue.empty():
                mic_chunk = mic_queue.get()
                logging.info(f'?? Sending {len(mic_chunk)} bytes of audio data.')
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
                if not message:  # Handle empty message (EOF or connection close)
                    logging.info('?? Received empty message (possibly EOF or WebSocket closing).')
                    break

                # Now handle valid JSON messages only
                message = json.loads(message)
                event_type = message['type']
                logging.info(f'?? Received WebSocket event: {event_type}')

                if event_type == 'response.audio.delta':
                    audio_content = base64.b64decode(message['delta'])
                    audio_buffer.extend(audio_content)
                    logging.info(f'?? Received {len(audio_content)} bytes, total buffer size: {len(audio_buffer)}')

                elif event_type == 'response.audio.done':
                    logging.info('?? AI finished speaking.')

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
        # Load API key from file
        api_key = read_api_key()

        ws = create_connection(WS_URL, header=[f'Authorization: Bearer {api_key}', 'OpenAI-Beta: realtime=v1'])
        logging.info('Connected to OpenAI WebSocket.')

        # Load system prompt from file
        system_prompt = read_system_prompt()

        ws.send(json.dumps({
            'type': 'response.create',
            'response': {
                'modalities': ['audio', 'text'],
                'instructions': system_prompt
            }
        }))

        # Start the recv and send threads
        receive_thread = threading.Thread(target=receive_audio_from_websocket, args=(ws,))
        receive_thread.start()

        mic_thread = threading.Thread(target=send_mic_audio_to_websocket, args=(ws,))
        mic_thread.start()

        # Wait for stop_event to be set
        while not stop_event.is_set():
            time.sleep(0.1)

        # Send a close frame and close the WebSocket gracefully
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

    # List all audio devices for debugging
    device_count = list_audio_devices(p)
    if device_count == 0:
        p.terminate()
        raise ValueError("No audio devices available. Please check your audio system.")

    # Get input device index (allow manual override via environment variable)
    input_device_index = os.getenv('INPUT_DEVICE_INDEX')
    if input_device_index is not None:
        try:
            input_device_index = int(input_device_index)
            device_info = p.get_device_info_by_index(input_device_index)
            logging.info(f'Using manually specified input device: {device_info["name"]} (index: {input_device_index})')
        except (ValueError, Exception) as e:
            logging.error(f'Invalid INPUT_DEVICE_INDEX: {e}. Falling back to default device.')
            input_device_index = None

    # Get default input device if not manually specified
    if input_device_index is None:
        try:
            default_device_info = p.get_default_input_device_info()
            input_device_index = default_device_info['index']
            logging.info(f'Default input device: {default_device_info["name"]} (index: {input_device_index})')
            if default_device_info['maxInputChannels'] == 0:
                logging.error(f'Default device {default_device_info["name"]} has no input channels.')
                p.terminate()
                raise ValueError('Default input device has no input channels.')
        except Exception as e:
            logging.error(f'Error retrieving default input device: {e}')
            p.terminate()
            raise ValueError('No default input device available.')

    # Check supported sample rates for microphone
    supported_rates = check_supported_sample_rates(p, input_device_index)
    logging.info(f'Supported sample rates for input device: {supported_rates}')

    mic_rate = RATE
    if supported_rates:
        if RATE not in supported_rates:
            logging.warning(f'Desired mic sample rate {RATE} not supported. Trying fallback rates.')
            for fallback_rate in FALLBACK_RATES:
                if fallback_rate in supported_rates:
                    mic_rate = fallback_rate
                    logging.info(f'Using fallback mic sample rate: {mic_rate}')
                    break
            else:
                logging.warning(f'No supported sample rates found in {FALLBACK_RATES}. Forcing 44100 Hz.')
                mic_rate = 44100
    else:
        logging.warning(f'No supported sample rates detected. Forcing 44100 Hz.')
        mic_rate = 44100

    # Speaker must always be 24000 Hz (OpenAI audio is 24kHz PCM16)
    spkr_rate = 24000

    try:
        logging.info(f'Opening microphone stream with sample rate: {mic_rate} Hz, device index: {input_device_index}')
        mic_stream = p.open(
            format=FORMAT,
            channels=1,
            rate=mic_rate,
            input=True,
            input_device_index=input_device_index,
            stream_callback=mic_callback,
            frames_per_buffer=CHUNK_SIZE
        )

        logging.info(f'Opening speaker stream with sample rate: {spkr_rate} Hz')
        spkr_stream = p.open(
            format=FORMAT,
            channels=1,
            rate=spkr_rate,
            output=True,
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
        logging.error(f'Failed to open audio streams (mic={mic_rate}, spkr={spkr_rate}): {e}')
        raise
    except Exception as e:
        logging.error(f'Error opening audio streams: {e}')
        raise
    finally:
        p.terminate()
        logging.info('Audio streams stopped and resources released. Exiting.')


if __name__ == '__main__':
    main()
