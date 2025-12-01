#!/bin/bash

# ============================================
# RakitanManager-Reborn Installer v2.0
# Modern, Robust, User-Friendly Installer
# ============================================

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# ASCII Art Logo
LOGO="
${CYAN}╔═══════════════════════════════════════════════════════════╗
║${BOLD}               OpenWrt Rakitan Manager Installer               ${CYAN}║
╚═══════════════════════════════════════════════════════════╝${NC}
"

# Animation characters
SPINNER=('⣾' '⣽' '⣻' '⢿' '⡿' '⣟' '⣯' '⣷')
CHECK_MARK="✓"
CROSS_MARK="✗"
ARROW="➜"

# Configuration
REPO_URL="https://github.com/rtaserver/RakitanManager-Reborn"
REPO_API="https://api.github.com/repos/rtaserver/RakitanManager-Reborn"
TEMP_DIR="/tmp/rakitanmanager_install"
BACKUP_DIR="/tmp/rakitanmanager_backup"
LOG_FILE="/tmp/rakitanmanager_install.log"
CONFIG_FILE="/etc/config/rakitanmanager"

# Required packages based on OpenWrt version
declare -A PACKAGE_MAP=(
    ["base"]="coreutils-sleep curl git git-http python3-pip bc screen adb httping jq procps-ng-pkill unzip dos2unix"
    ["php7"]="php7-mod-curl php7-mod-session php7-mod-zip php7-cgi"
    ["php8"]="php8-mod-curl php8-mod-session php8-mod-zip php8-cgi"
    ["python"]="python3-requests python3-pip python3-setuptools"
)

# Global variables
INSTALLATION_STEPS=0
CURRENT_STEP=0
SUCCESS_STEPS=0
FAILED_STEPS=0
ARCH=""
BRANCH=""
PACKAGE_MANAGER=""
OS_INFO=""

# ============================================
# Utility Functions
# ============================================

# Print colored output
print_color() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Print with timestamp
log() {
    local message=$1
    local level=${2:-INFO}
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "${CYAN}[${timestamp}]${NC} ${BOLD}[${level}]${NC} ${message}" | tee -a "$LOG_FILE"
}

# Print step header
step_header() {
    local step=$1
    local title=$2
    INSTALLATION_STEPS=$((INSTALLATION_STEPS + 1))
    CURRENT_STEP=$step
    echo -e "\n${BLUE}${BOLD}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║  Step ${step}: ${title}"
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}\n"
}

# Print progress bar
progress_bar() {
    local current=$1
    local total=$2
    local width=50
    local percentage=$((current * 100 / total))
    local completed=$((current * width / total))
    local remaining=$((width - completed))
    
    printf "\r${CYAN}[${NC}"
    printf "%${completed}s" | tr ' ' '█'
    printf "%${remaining}s" | tr ' ' '░'
    printf "${CYAN}]${NC} ${BOLD}${percentage}%%${NC} (${current}/${total})"
}

# Spinner animation
spinner() {
    local pid=$1
    local message=$2
    local delay=0.1
    local i=0

    local sleep_cmd="${SLEEP_CMD:-sleep}"

    while kill -0 $pid 2>/dev/null; do
        printf "\r${CYAN}[${SPINNER[$i]}${CYAN}]${NC} ${message}"
        i=$(( (i+1) % ${#SPINNER[@]} ))
        "$sleep_cmd" "$delay" 2>/dev/null || sleep "$delay" 2>/dev/null
    done
    printf "\r${GREEN}[${CHECK_MARK}${GREEN}]${NC} ${message}\n"
}

check_sleep_fractional() {
    if command -v sleep >/dev/null 2>&1; then
        if sleep 0.1 2>/dev/null; then
            return 0
        fi
    fi
    return 1
}

ensure_coreutils_sleep_installed() {
    if check_sleep_fractional; then
        return 0
    fi

    log "Fractional 'sleep' not available. Installing 'coreutils-sleep'..." "INFO"

    if [ -z "$PACKAGE_MANAGER" ]; then
        detect_package_manager || return 1
    fi

    if install_package "coreutils-sleep"; then
        log "coreutils-sleep installed" "SUCCESS"
        return 0
    else
        log "Failed to install coreutils-sleep. Spinner may be less smooth." "WARNING"
        return 1
    fi
}

set_sleep_cmd() {
    if command -v sleep >/dev/null 2>&1; then
        SLEEP_CMD="$(command -v sleep)"
    else
        SLEEP_CMD="sleep"
    fi
}

# Check command success
check_success() {
    if [ $? -eq 0 ]; then
        SUCCESS_STEPS=$((SUCCESS_STEPS + 1))
        echo -e "${GREEN}${CHECK_MARK} Success${NC}"
        return 0
    else
        FAILED_STEPS=$((FAILED_STEPS + 1))
        echo -e "${RED}${CROSS_MARK} Failed${NC}"
        return 1
    fi
}

# Check internet connectivity
check_internet() {
    log "Checking internet connectivity..."
    if ping -c 1 -W 3 google.com >/dev/null 2>&1; then
        return 0
    else
        log "No internet connection detected" "ERROR"
        return 1
    fi
}

# Check disk space
check_disk_space() {
    local required=$1
    local available=$(df /tmp | awk 'NR==2 {print $4}')
    
    if [ "$available" -lt "$required" ]; then
        log "Insufficient disk space. Required: ${required}KB, Available: ${available}KB" "ERROR"
        return 1
    fi
    return 0
}

# Detect package manager
detect_package_manager() {
    if [ -x "/bin/opkg" ]; then
        PACKAGE_MANAGER="opkg"
    elif [ -x "/usr/bin/apk" ]; then
        PACKAGE_MANAGER="apk"
    else
        log "Unsupported package manager" "ERROR"
        return 1
    fi
    log "Detected package manager: ${PACKAGE_MANAGER}" "INFO"
    return 0
}

# Detect system info
detect_system() {
    if [ -f "/etc/openwrt_release" ]; then
        . /etc/openwrt_release
        ARCH="$DISTRIB_ARCH"
        OS_INFO="${DISTRIB_ID} ${DISTRIB_RELEASE} (${DISTRIB_CODENAME})"
        
        case "$DISTRIB_RELEASE" in
            *"21.02"*) BRANCH="openwrt-21.02" ;;
            *"22.03"*) BRANCH="openwrt-22.03" ;;
            *"23.05"*) BRANCH="openwrt-23.05" ;;
            *"24.10"*) BRANCH="openwrt-24.10" ;;
            "SNAPSHOT") BRANCH="SNAPSHOT" ;;
            *) BRANCH="unknown" ;;
        esac
    else
        log "Not running on OpenWrt" "ERROR"
        return 1
    fi
    
    log "Detected: ${OS_INFO}" "INFO"
    log "Architecture: ${ARCH}" "INFO"
    log "Branch: ${BRANCH}" "INFO"
    return 0
}

# Install package with retry
install_package() {
    local package=$1
    local max_retries=3
    local retry_count=0
    
    while [ $retry_count -lt $max_retries ]; do
        case $PACKAGE_MANAGER in
            "opkg")
                if opkg list-installed | grep -q "^${package} "; then
                    log "Package already installed: ${package}" "INFO"
                    return 0
                fi
                
                log "Installing: ${package}" "INFO"
                opkg update >/dev/null 2>&1
                if opkg install "$package" >/dev/null 2>&1; then
                    log "Successfully installed: ${package}" "SUCCESS"
                    return 0
                fi
                ;;
            "apk")
                if apk info | grep -q "^${package}\$"; then
                    log "Package already installed: ${package}" "INFO"
                    return 0
                fi
                
                log "Installing: ${package}" "INFO"
                apk update >/dev/null 2>&1
                if apk add "$package" >/dev/null 2>&1; then
                    log "Successfully installed: ${package}" "SUCCESS"
                    return 0
                fi
                ;;
        esac
        
        retry_count=$((retry_count + 1))
        log "Retrying installation (${retry_count}/${max_retries}): ${package}" "WARNING"
        sleep 2
    done
    
    log "Failed to install: ${package} after ${max_retries} attempts" "ERROR"
    return 1
}

# Get latest release from GitHub
get_latest_release() {
    log "Fetching latest release information..." "INFO"
    
    local release_info=$(curl -s "$REPO_API/releases/latest" 2>/dev/null)
    if [ -z "$release_info" ]; then
        log "Failed to fetch release info. Using default branch." "WARNING"
        echo "main"
        return
    fi
    
    local tag_name=$(echo "$release_info" | grep '"tag_name"' | cut -d'"' -f4)
    if [ -n "$tag_name" ]; then
        log "Latest release: ${tag_name}" "INFO"
        echo "$tag_name"
    else
        log "No releases found. Using main branch." "WARNING"
        echo "main"
    fi
}

# Download with progress
download_file() {
    local url=$1
    local output=$2
    local description=$3
    
    log "Downloading ${description}..." "INFO"
    
    if command -v wget >/dev/null 2>&1; then
        wget --show-progress -q -O "$output" "$url" 2>&1 | \
        stdbuf -o0 awk '/[.] +[0-9][0-9]?[0-9]?%/ {print substr($0,63)}' | \
        while read line; do
            printf "\r${CYAN}[${NC}${BOLD}Downloading${NC}${CYAN}]${NC} %s" "$line"
        done
        printf "\n"
    elif command -v curl >/dev/null 2>&1; then
        curl -# -L -o "$output" "$url" 2>&1 | \
        stdbuf -o0 tr '\r' '\n' | \
        stdbuf -o0 awk '{if(NR==1){printf "\r" $0}}'
        printf "\n"
    else
        log "No download tool available" "ERROR"
        return 1
    fi
    
    if [ -f "$output" ] && [ -s "$output" ]; then
        log "Download completed: $(ls -lh "$output" | awk '{print $5}')" "SUCCESS"
        return 0
    else
        log "Download failed" "ERROR"
        return 1
    fi
}

# Backup existing installation
backup_existing() {
    if [ -d "/usr/share/rakitanmanager" ] || [ -d "/www/rakitanmanager" ]; then
        log "Backing up existing installation..." "INFO"
        
        mkdir -p "$BACKUP_DIR"
        
        # Backup modems.json if exists
        if [ -f "/usr/share/rakitanmanager/modems.json" ]; then
            cp "/usr/share/rakitanmanager/modems.json" "$BACKUP_DIR/modems.json"
            log "Backed up modems.json" "INFO"
        fi
        
        # Backup config if exists
        if [ -f "$CONFIG_FILE" ]; then
            cp "$CONFIG_FILE" "$BACKUP_DIR/rakitanmanager.config"
            log "Backed up configuration" "INFO"
        fi
        
        # Backup logs if exist
        if [ -f "/usr/share/rakitanmanager/rakitanmanager.log" ]; then
            cp "/usr/share/rakitanmanager/rakitanmanager.log" "$BACKUP_DIR/rakitanmanager.log"
            log "Backed up log file" "INFO"
        fi
        
        log "Backup completed at: ${BACKUP_DIR}" "SUCCESS"
    fi
}

# Restore backup
restore_backup() {
    if [ -d "$BACKUP_DIR" ]; then
        log "Restoring backup..." "INFO"
        
        if [ -f "$BACKUP_DIR/modems.json" ]; then
            cp "$BACKUP_DIR/modems.json" "/usr/share/rakitanmanager/modems.json"
            log "Restored modems.json" "INFO"
        fi
        
        if [ -f "$BACKUP_DIR/rakitanmanager.config" ]; then
            cp "$BACKUP_DIR/rakitanmanager.config" "$CONFIG_FILE"
            log "Restored configuration" "INFO"
        fi
        
        log "Backup restoration completed" "SUCCESS"
    fi
}

# Clean up temporary files
cleanup() {
    log "Cleaning up temporary files..." "INFO"
    rm -rf "$TEMP_DIR" 2>/dev/null
    log "Cleanup completed" "SUCCESS"
}

# Create directory structure
create_directories() {
    local dirs=(
        "/usr/share/rakitanmanager"
        "/www/rakitanmanager"
        "/etc/init.d"
        "$TEMP_DIR"
    )
    
    for dir in "${dirs[@]}"; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir"
            log "Created directory: ${dir}" "INFO"
        fi
    done
}

# Set file permissions
set_permissions() {
    log "Setting permissions..." "INFO"
    
    # Make scripts executable
    find "/usr/share/rakitanmanager" -name "*.sh" -type f -exec chmod +x {} \;
    
    # Set correct ownership
    if command -v chown >/dev/null 2>&1; then
        chown -R root:root "/usr/share/rakitanmanager"
        chown -R root:root "/www/rakitanmanager"
    fi
    
    # Set init script executable
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        chmod +x "/etc/init.d/rakitanmanager"
    fi
    
    log "Permissions set successfully" "SUCCESS"
}

# Validate installation
validate_installation() {
    local errors=0
    
    log "Validating installation..." "INFO"
    
    # Check required directories
    local required_dirs=(
        "/usr/share/rakitanmanager"
        "/www/rakitanmanager"
    )
    
    for dir in "${required_dirs[@]}"; do
        if [ ! -d "$dir" ]; then
            log "Missing directory: ${dir}" "ERROR"
            errors=$((errors + 1))
        fi
    done
    
    # Check required files
    local required_files=(
        "/usr/share/rakitanmanager/rakitanmanager.py"
        "/www/rakitanmanager/index.php"
        "/etc/init.d/rakitanmanager"
        "/etc/config/rakitanmanager"
    )
    
    for file in "${required_files[@]}"; do
        if [ ! -f "$file" ]; then
            log "Missing file: ${file}" "ERROR"
            errors=$((errors + 1))
        fi
    done
    
    # Check Python dependencies
    if command -v python3 >/dev/null 2>&1; then
        if ! python3 -c "import requests" 2>/dev/null; then
            log "Python module 'requests' not found" "WARNING"
        fi
        if ! python3 -c "import huawei_lte_api" 2>/dev/null; then
            log "Python module 'huawei_lte_api' not found" "WARNING"
        fi
    fi
    
    # Check PHP modules
    if command -v php >/dev/null 2>&1; then
        if ! php -m | grep -q "curl"; then
            log "PHP module 'curl' not found" "WARNING"
        fi
        if ! php -m | grep -q "json"; then
            log "PHP module 'json' not found" "WARNING"
        fi
    fi
    
    if [ $errors -eq 0 ]; then
        log "Installation validation passed!" "SUCCESS"
        return 0
    else
        log "Installation validation failed with ${errors} error(s)" "ERROR"
        return 1
    fi
}

# Show installation summary
show_summary() {
    echo -e "\n${CYAN}${BOLD}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║                     INSTALLATION SUMMARY                     "
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}\n"
    
    echo -e "${BOLD}System Information:${NC}"
    echo -e "  ${ARROW} OS: ${OS_INFO}"
    echo -e "  ${ARROW} Architecture: ${ARCH}"
    echo -e "  ${ARROW} Branch: ${BRANCH}"
    echo -e "  ${ARROW} Package Manager: ${PACKAGE_MANAGER}"
    
    echo -e "\n${BOLD}Installation Results:${NC}"
    echo -e "  ${GREEN}${CHECK_MARK}${NC} Successful steps: ${SUCCESS_STEPS}/${INSTALLATION_STEPS}"
    if [ $FAILED_STEPS -gt 0 ]; then
        echo -e "  ${RED}${CROSS_MARK}${NC} Failed steps: ${FAILED_STEPS}"
    fi
    
    echo -e "\n${BOLD}Installed Components:${NC}"
    echo -e "  ${ARROW} Core scripts: /usr/share/rakitanmanager/"
    echo -e "  ${ARROW} Web interface: /www/rakitanmanager/"
    echo -e "  ${ARROW} Configuration: /etc/config/rakitanmanager"
    echo -e "  ${ARROW} Init script: /etc/init.d/rakitanmanager"
    
    echo -e "\n${BOLD}Next Steps:${NC}"
    echo -e "  1. Access web interface: http://<your-router-ip>/rakitanmanager"
    echo -e "  2. Configure your modems in the web interface"
    echo -e "  3. Start the service: /etc/init.d/rakitanmanager start"
    echo -e "  4. Enable auto-start: /etc/init.d/rakitanmanager enable"
    
    if [ -d "$BACKUP_DIR" ]; then
        echo -e "\n${YELLOW}${BOLD}Note:${NC} Backup files are saved at: ${BACKUP_DIR}"
        echo -e "      You can remove them after confirming everything works."
    fi
    
    echo -e "\n${GREEN}${BOLD}Installation completed!${NC}\n"
}

# ============================================
# Main Installation Function
# ============================================

install_rakitanmanager() {
    # Clear screen and show logo
    clear
    echo -e "$LOGO"
    
    # Initialize log file
    > "$LOG_FILE"
    log "Starting RakitanManager-Reborn installation..." "INFO"
    
    # Step 1: Check prerequisites
    step_header 1 "System Check"
    {
        detect_system &&
        detect_package_manager &&
        ensure_coreutils_sleep_installed &&
        set_sleep_cmd &&
        check_internet &&
        check_disk_space 50000
    } && check_success || {
        log "Prerequisite check failed" "ERROR"
        return 1
    }
    
    # Step 2: Install dependencies
    step_header 2 "Installing Dependencies"
    {
        # Determine PHP version
        local php_packages=""
        if [[ "$BRANCH" == "openwrt-21.02" ]]; then
            php_packages="${PACKAGE_MAP[php7]}"
        else
            php_packages="${PACKAGE_MAP[php8]}"
        fi
        
        # Install all packages
        all_packages="${PACKAGE_MAP[base]} ${php_packages} ${PACKAGE_MAP[python]}"
        
        for package in $all_packages; do
            install_package "$package"
        done
    } && check_success || {
        log "Some dependencies failed to install" "WARNING"
    }
    
    # Step 3: Backup existing installation
    step_header 3 "Backup Existing Installation"
    backup_existing && check_success
    
    # Step 4: Download latest release
    step_header 4 "Downloading RakitanManager-Reborn"
    {
        create_directories
        local latest_release=$(get_latest_release)
        
        if [[ "$latest_release" == "main" ]]; then
            local download_url="${REPO_URL}/archive/refs/heads/main.zip"
        else
            local download_url="${REPO_URL}/archive/refs/tags/${latest_release}.zip"
        fi
        
        local zip_file="${TEMP_DIR}/rakitanmanager.zip"
        download_file "$download_url" "$zip_file" "RakitanManager ${latest_release}"
        
        if [ ! -f "$zip_file" ]; then
            log "Download failed" "ERROR"
            return 1
        fi
        
        # Extract with progress
        log "Extracting files..." "INFO"
        if command -v unzip >/dev/null 2>&1; then
            unzip -o "$zip_file" -d "$TEMP_DIR" >/dev/null 2>&1
            log "Extraction completed" "SUCCESS"
        else
            log "unzip command not found" "ERROR"
            return 1
        fi
    } && check_success || {
        log "Download/extraction failed" "ERROR"
        return 1
    }
    
    # Step 5: Install files
    step_header 5 "Installing Files"
    {
        # Find extracted directory
        local extracted_dir=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "*RakitanManager-Reborn*" | head -1)
        
        if [ -z "$extracted_dir" ]; then
            log "Could not find extracted files" "ERROR"
            return 1
        fi
        
        log "Copying core files..." "INFO"
        # Copy core files
        cp -rf "${extracted_dir}/core/"* "/usr/share/rakitanmanager/" 2>/dev/null
        cp -rf "${extracted_dir}/web/"* "/www/rakitanmanager/" 2>/dev/null
        cp -rf "${extracted_dir}/config/"* "/etc/config/" 2>/dev/null
        cp -rf "${extracted_dir}/init.d/"* "/etc/init.d/" 2>/dev/null
        
        # Fix line endings
        if command -v dos2unix >/dev/null 2>&1; then
            find "/usr/share/rakitanmanager" -name "*.sh" -type f -exec dos2unix {} \;
        fi
        
        log "Files installed successfully" "SUCCESS"
    } && check_success || {
        log "File installation failed" "ERROR"
        return 1
    }
    
    # Step 6: Restore backup
    step_header 6 "Restoring Configuration"
    restore_backup && check_success
    
    # Step 7: Set permissions
    step_header 7 "Setting Permissions"
    set_permissions && check_success
    
    # Step 8: Validate installation
    step_header 8 "Validating Installation"
    validate_installation && check_success
    
    # Step 9: Cleanup
    step_header 9 "Cleaning Up"
    cleanup && check_success
    
    # Show summary
    show_summary
    
    # Enable service if available
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        echo -e "${CYAN}Would you like to enable startup RakitanManager-Reborn service? (y/n)${NC}"
        read -r response
        if [[ "$response" =~ ^[Yy]$ ]]; then
            /etc/init.d/rakitanmanager enable
            log "Service enabled and started" "SUCCESS"
        fi
    fi
    
    log "Installation process completed" "INFO"
    return 0
}

# ============================================
# Uninstall Function
# ============================================

uninstall_rakitanmanager() {
    clear
    echo -e "${RED}${BOLD}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                    UNINSTALL WARNING                      ║"
    echo "╚═══════════════════════════════════════════════════════════╝${NC}\n"
    
    echo -e "${YELLOW}This will remove RakitanManager and all its components.${NC}"
    echo -e "${YELLOW}Your configuration and modem data will be backed up.${NC}\n"
    
    echo -e "The following will be removed:"
    echo -e "  ${ARROW} /usr/share/rakitanmanager/"
    echo -e "  ${ARROW} /www/rakitanmanager/"
    echo -e "  ${ARROW} /etc/config/rakitanmanager"
    echo -e "  ${ARROW} /etc/init.d/rakitanmanager"
    echo -e "  ${ARROW} Log files and temporary data\n"
    
    echo -e "${RED}This action cannot be undone!${NC}\n"
    
    read -p "Are you sure you want to continue? (type 'YES' to confirm): " confirmation
    
    if [ "$confirmation" != "YES" ]; then
        echo -e "${GREEN}Uninstall cancelled.${NC}"
        exit 0
    fi
    
    log "Starting uninstallation..." "INFO"
    
    # Backup before removal
    backup_existing
    
    # Stop service if running
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        /etc/init.d/rakitanmanager stop 2>/dev/null
        /etc/init.d/rakitanmanager disable 2>/dev/null
    fi
    
    # Remove files
    rm -rf "/usr/share/rakitanmanager"
    rm -rf "/www/rakitanmanager"
    rm -f "/etc/config/rakitanmanager"
    rm -f "/etc/init.d/rakitanmanager"
    
    # Remove from uci config if exists
    if command -v uci >/dev/null 2>&1; then
        uci delete rakitanmanager 2>/dev/null
        uci commit 2>/dev/null
    fi
    
    log "Uninstallation completed" "SUCCESS"
    echo -e "\n${GREEN}RakitanManager-Reborn has been uninstalled.${NC}"
    echo -e "${YELLOW}Backup files are saved at: ${BACKUP_DIR}${NC}"
    echo -e "You can remove this directory if you don't need the backup.\n"
}

# ============================================
# Update Function
# ============================================

update_rakitanmanager() {
    clear
    echo -e "${CYAN}${BOLD}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                  UPDATE RAKITANMANAGER                    ║"
    echo "╚═══════════════════════════════════════════════════════════╝${NC}\n"
    
    if [ ! -d "/usr/share/rakitanmanager" ]; then
        echo -e "${RED}RakitanManager-Reborn is not installed. Please install it first.${NC}\n"
        exit 1
    fi
    
    echo -e "This will update RakitanManager-Reborn to the latest version.\n"
    read -p "Continue? (y/n): " response
    
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Update cancelled.${NC}"
        exit 0
    fi
    
    # Run installer which will backup and update
    install_rakitanmanager
}

# ============================================
# Repair Function
# ============================================

repair_installation() {
    clear
    echo -e "${YELLOW}${BOLD}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                  REPAIR INSTALLATION                      ║"
    echo "╚═══════════════════════════════════════════════════════════╝${NC}\n"
    
    echo -e "This will attempt to repair your RakitanManager-Reborn installation.\n"
    echo -e "The following will be checked and fixed:"
    echo -e "  ${ARROW} Missing dependencies"
    echo -e "  ${ARROW} File permissions"
    echo -e "  ${ARROW} Configuration files"
    echo -e "  ${ARROW} Service initialization\n"
    
    read -p "Continue? (y/n): " response
    
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Repair cancelled.${NC}"
        exit 0
    fi
    
    log "Starting repair process..." "INFO"
    
    # Check and install dependencies
    detect_package_manager
    for package in ${PACKAGE_MAP[base]}; do
        install_package "$package"
    done
    
    # Fix permissions
    set_permissions
    
    # Validate installation
    validate_installation
    
    echo -e "\n${GREEN}Repair process completed!${NC}\n"
}

# ============================================
# Main Menu
# ============================================

show_menu() {
    clear
    echo -e "$LOGO"
    
    echo -e "${CYAN}${BOLD}Select an option:${NC}\n"
    echo -e "  ${GREEN}1${NC}. Install RakitanManager-Reborn (Fresh installation)"
    echo -e "  ${YELLOW}2${NC}. Update RakitanManager-Reborn (To latest version)"
    echo -e "  ${BLUE}3${NC}. Repair Installation (Fix issues)"
    echo -e "  ${RED}4${NC}. Uninstall RakitanManager-Reborn (Remove completely)"
    echo -e "  ${CYAN}5${NC}. Check System Compatibility"
    echo -e "  ${WHITE}6${NC}. View Installation Log"
    echo -e "  ${GRAY}0${NC}. Exit\n"
    
    read -p "Enter your choice [0-6]: " choice
    
    case $choice in
        1) install_rakitanmanager ;;
        2) update_rakitanmanager ;;
        3) repair_installation ;;
        4) uninstall_rakitanmanager ;;
        5)
            clear
            echo -e "${CYAN}${BOLD}System Compatibility Check${NC}\n"
            detect_system
            detect_package_manager
            check_internet
            echo -e "\n${GREEN}Compatibility check completed!${NC}\n"
            read -p "Press Enter to continue..."
            show_menu
            ;;
        6)
            clear
            echo -e "${CYAN}${BOLD}Installation Log${NC}\n"
            if [ -f "$LOG_FILE" ]; then
                cat "$LOG_FILE"
            else
                echo "No log file found."
            fi
            echo -e "\n"
            read -p "Press Enter to continue..."
            show_menu
            ;;
        0)
            echo -e "\n${GREEN}Goodbye!${NC}\n"
            exit 0
            ;;
        *)
            echo -e "\n${RED}Invalid option!${NC}"
            sleep 1
            show_menu
            ;;
    esac
}

# ============================================
# Script Entry Point
# ============================================

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}This script must be run as root!${NC}"
    echo -e "Please run: ${CYAN}sudo bash $0${NC}"
    exit 1
fi

# Create temp directory
mkdir -p "$TEMP_DIR"

# Show main menu
show_menu

# Cleanup on exit
trap cleanup EXIT