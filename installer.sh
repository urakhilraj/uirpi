#!/bin/bash

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
