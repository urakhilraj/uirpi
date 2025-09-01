#!/bin/bash
sudo rm -rf /var/www/html/*
sudo rm -rf /var/www/html/.* 2>/dev/null

cecho () {
    declare -A colors;
    colors=(\
        ['black']='\E[0;47m'\
        ['red']='\E[0;31m'\
        ['green']='\E[0;32m'\
        ['yellow']='\E[0;33m'\
        ['blue']='\E[0;34m'\
        ['magenta']='\E[0;35m'\
        ['cyan']='\E[0;36m'\
        ['white']='\E[0;37m'\
    );

    local defaultMSG="No message passed.";
    local defaultColor="black";
    local defaultNewLine=true;

    while [[ $# -gt 1 ]];
    do
    key="$1";

    case $key in
        -c|--color)
            color="$2";
            shift;
        ;;
        -n|--noline)
            newLine=false;
        ;;
        *)
            # unknown option
        ;;
    esac
    shift;
    done

    message=${1:-$defaultMSG};
    color=${color:-$defaultColor};
    newLine=${newLine:-$defaultNewLine};

    echo -en "${colors[$color]}";
    echo -en "$message";
    if [ "$newLine" = true ] ; then
        echo;
    fi
    tput sgr0;
}

### Colors ##
ESC=$(printf '\033') RESET="${ESC}[0m" BLACK="${ESC}[30m" RED="${ESC}[31m"
GREEN="${ESC}[32m" YELLOW="${ESC}[33m" BLUE="${ESC}[34m" MAGENTA="${ESC}[35m"
CYAN="$ESC[36m" WHITE="${ESC}[37m" DEFAULT="${ESC}[39m"
cyanprint() { printf "${CYAN}%s${RESET}\n" "$1"; }
_process() {
  echo -e "\n"
  cyanprint " → $@"
}
_success() {
  printf "\n%s✓ Success:%s\n" "$(tput setaf 2)" "$(tput sgr0) $1"
}

cecho -c 'blue' "***************************************"
cecho -c 'blue' "Welcome to the RPi Dashboard installer!"
cecho -c 'blue' "***************************************"

_process "Checking and installing required dependencies..."
if ! command -v git >/dev/null; then
    _process "Installing Git..."
    sudo apt-get update
    sudo apt-get install -y git
    if [ $? -ne 0 ]; then
        echo "${RED}Failed to install Git. Please check your internet connection and try again.${RESET}"
        exit 1
    fi
fi

_process "Installing Apache2..."
sudo apt-get install network-manager
echo 'www-data ALL=(ALL) NOPASSWD: /usr/bin/nmcli' | sudo EDITOR='tee -a' visudo
echo 'www-data ALL=NOPASSWD: /sbin/shutdown' | sudo EDITOR='tee -a' visudo
sudo apt-get update
sudo apt-get install -y apache2
if [ $? -ne 0 ]; then
    echo "${RED}Failed to install Apache2. Please check your internet connection and try again.${RESET}"
    exit 1
fi

_process "Installing PHP 8.1 and required tools..."
sudo apt-get install -y lsb-release
curl https://packages.sury.org/php/apt.gpg | sudo tee /usr/share/keyrings/suryphp-archive-keyring.gpg >/dev/null
echo "deb [signed-by=/usr/share/keyrings/suryphp-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/sury-php.list
sudo apt-get update
sudo apt-get install -y software-properties-common
sudo apt-add-repository ppa:ondrej/php -y
sudo apt-get update
sudo apt-get install -y php8.1-cli php8.1-fpm libapache2-mod-php
if [ $? -ne 0 ]; then
    echo "${RED}Failed to install PHP 8.1. Please check your internet connection and try again.${RESET}"
    exit 1
fi

_process "Starting Apache2 service..."
sudo systemctl start apache2
sudo systemctl enable apache2

sudo groupadd www-data
sudo usermod -a -G www-data www-data
sudo usermod -a -G www-data ${whoami}
sudo chown -R ${whoami}:www-data /var/www/html
sudo chmod -R 775 /var/www/html/

hostn="`hostname`"
cecho -c 'blue' "This setup will install the RPi Dashboard to -> /var/www/html/"
_process "Clearing existing content in /var/www/html/..."
sudo rm -rf /var/www/html/*
if [ $? -ne 0 ]; then
    echo "${RED}Failed to clear /var/www/html/ directory!${RESET}"
    echo "${YELLOW}Please make sure you have necessary permissions to write into that folder.${RESET}"
    exit 1
fi

_process "Cloning RPi Dashboard to /var/www/html/..."
git clone https://github.com/urakhilraj/uirpi /var/www/html/
if [ $? -ne 0 ]; then
    echo "${RED}Failed to clone the repository!${RESET}"
    echo "${YELLOW}Please check your internet connection and GitHub availability.${RESET}"
    exit 1
fi

_process "Setting up valid permissions for /var/www/html/..."
sudo chown -R ${whoami}:www-data /var/www/html
sudo chmod -R 775 /var/www/html
if [ $? -ne 0 ]; then
    echo "${RED}Failed to set permissions!${RESET}"
    exit 1
fi

_success "Installation done! To access the RPi dashboard open a web browser and access URL: http://$hostn/ !"
_process "Please report any issues here: https://github.com/urakhilraj/uirpi/issues. Thank you!"

sudo mv /usr/share/plymouth/themes/pix/splash.png /usr/share/plymouth/themes/pix/splash_default.png
sudo cp /var/www/html/splash.png /usr/share/plymouth/themes/pix
sudo plymouth-set-default-theme --rebuild-initrd pix
pcmanfm --set-wallpaper /var/www/html/wallpaper.jpg

# Exit on any error
set -e

# Define variables
SERVICE_FILE="/etc/systemd/system/kiosk-browser.service"
USER="acubot"
GROUP="acubot"
HOME_DIR="/var/www/html"
WRAPPER_SCRIPT="$HOME_DIR/start_kiosk_voice.sh"
ENV_DIR="$HOME_DIR/env"
DASHBOARD_URL="http://acubotz.local/poster_slider.php"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "This script must be run as root. Please use sudo."
    exit 1
fi

echo "=== Installing system dependencies ==="
apt-get update
apt-get install -y python3 python3-venv python3-pip \
                   portaudio19-dev python3-pyaudio \
                   libasound-dev libpulse-dev \
                   chromium-browser

echo "=== Creating Python virtual environment at $ENV_DIR ==="
sudo -u $USER python3 -m venv "$ENV_DIR"

echo "=== Installing required Python packages (pyaudio, websocket-client) ==="
sudo -u $USER $ENV_DIR/bin/pip install --upgrade pip
sudo -u $USER $ENV_DIR/bin/pip install pyaudio websocket-client

echo "=== Creating wrapper script at $WRAPPER_SCRIPT ==="
cat > "$WRAPPER_SCRIPT" << EOL
#!/bin/bash

# Start Chromium in kiosk mode
/usr/bin/chromium-browser --noerrdialogs --kiosk --disable-infobars \
--disable-session-crashed-bubble --disable-restore-session-state \
$DASHBOARD_URL &

# Start Voice Assistant Bot
$ENV_DIR/bin/python $HOME_DIR/voice.py
EOL

chmod +x "$WRAPPER_SCRIPT"
chown $USER:$GROUP "$WRAPPER_SCRIPT"

echo "=== Creating systemd service file at $SERVICE_FILE ==="
cat > "$SERVICE_FILE" << EOL
[Unit]
Description=Kiosk Browser + Voice Assistant Bot
After=graphical.target sound.target network.target
Wants=graphical.target sound.target network.target

[Service]
Type=simple
User=$USER
Group=$GROUP
WorkingDirectory=$HOME_DIR
Environment="VIRTUAL_ENV=$ENV_DIR"
Environment="PATH=$ENV_DIR/bin:/usr/bin"
Environment="PULSE_SERVER=unix:/run/user/1000/pulse/native"
Environment=DISPLAY=:0
Environment=XAUTHORITY=$HOME_DIR/.Xauthority

ExecStart=$WRAPPER_SCRIPT
Restart=always
RestartSec=10

[Install]
WantedBy=graphical.target
EOL

chmod 644 "$SERVICE_FILE"

echo "=== Reloading systemd and enabling service ==="
systemctl daemon-reload
systemctl enable kiosk-browser.service
systemctl start kiosk-browser.service

echo "=== Installation complete! ==="
echo "Check status with: systemctl status kiosk-browser.service"
# Ensure graphical target is default
echo "Setting graphical target as default..."
systemctl set-default graphical.target

# Enable and start the kiosk service
echo "Enabling and starting kiosk-browser service..."






LINE='www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active kiosk-browser.service, /bin/systemctl reload kiosk-browser.service, /bin/systemctl start kiosk-browser.service, /bin/systemctl stop kiosk-browser.service, /bin/systemctl enable kiosk-browser.service, /bin/systemctl disable kiosk-browser.service'

FILE='/etc/sudoers.d/www-data'

# Create the file if it does not exist
if [ ! -f "$FILE" ]; then
  echo "Creating $FILE..."
  sudo touch "$FILE"
fi

# Add the line if it's not already present
if ! grep -Fxq "$LINE" "$FILE"; then
  echo "$LINE" | sudo tee -a "$FILE" > /dev/null
  echo "Line added to $FILE"
else
  echo "Line already exists in $FILE"
fi

# Set correct permissions and ownership
sudo chmod 440 "$FILE"
sudo chown root:root "$FILE"

# Validate sudoers syntax
sudo visudo -cf "$FILE"
if [ $? -eq 0 ]; then
  echo "sudoers file is valid."
else
  echo "ERROR: sudoers file has syntax errors!"
fi

sudo chmod 664 /var/www/html/poster_manager.php
sudo chown www-data:www-data /var/www/html/poster_manager.php
sudo chmod 664 /var/www/html/service_control.php
sudo chown www-data:www-data /var/www/html/service_control.php
sudo chmod -R 775 /var/www/html/posters/
sudo chown -R www-data:www-data /var/www/html/posters/
sudo chmod 664 /var/www/html/posters/poster_settings.json
sudo chown www-data:www-data /var/www/html/posters/poster_settings.json

echo "Service installation complete! The poster slider should now run in full-screen kiosk mode on startup."
echo "Access the slider at $DASHBOARD_URL"
echo "Reboot the system to verify the kiosk mode setup: sudo reboot"

# Delete the installer script
echo "Removing installer script ($0)..."
rm -f "$0"
