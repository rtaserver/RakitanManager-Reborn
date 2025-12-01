#!/bin/sh

# ============================================
# RakitanManager-Reborn Installer
# ============================================

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Animation characters
SPINNER="⣾⣽⣻⢿⡿⣟⣯⣷"
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
PACKAGE_BASE="coreutils-sleep curl git git-http python3-pip bc screen adb httping jq procps-ng-pkill unzip dos2unix"
PACKAGE_PHP7="php7-mod-curl php7-mod-session php7-mod-zip php7-cgi"
PACKAGE_PHP8="php8-mod-curl php8-mod-session php8-mod-zip php8-cgi"
PACKAGE_PYTHON="python3-requests python3-pip python3-setuptools"

# Global variables
INSTALLATION_STEPS=0
CURRENT_STEP=0
SUCCESS_STEPS=0
FAILED_STEPS=0
ARCH=""
BRANCH=""
PACKAGE_MANAGER=""
OS_INFO=""
EXTRACTED_DIR=""

# ============================================
# Utility Functions
# ============================================

print_color() {
    echo -e "${1}${2}${NC}"
}

log() {
    local message="$1"
    local level="${2:-INFO}"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "unknown")
    echo -e "${CYAN}[${timestamp}]${NC} [${BOLD}${level}${NC}] ${message}" | tee -a "$LOG_FILE" >&2
}

step_header() {
    INSTALLATION_STEPS=$((INSTALLATION_STEPS + 1))
    CURRENT_STEP="$1"
    echo -e "\n${BLUE}${BOLD}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║  Step ${1}: ${2}"
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}\n"
}

check_success() {
    if [ $? -eq 0 ]; then
        SUCCESS_STEPS=$((SUCCESS_STEPS + 1))
        print_color "$GREEN" "✓ Success"
        return 0
    else
        FAILED_STEPS=$((FAILED_STEPS + 1))
        print_color "$RED" "✗ Failed"
        return 1
    fi
}

check_internet() {
    log "Checking internet connectivity..."
    if ping -c 1 -W 3 8.8.8.8 >/dev/null 2>&1 || ping -c 1 -W 3 google.com >/dev/null 2>&1; then
        log "Internet connectivity check passed" "SUCCESS"
        return 0
    else
        log "No internet connection detected. Please check your network." "ERROR"
        return 1
    fi
}

check_disk_space() {
    local required="$1"
    local available
    available=$(df /tmp 2>/dev/null | awk 'NR==2 {print $4}' || echo "0")
    if [ "$available" -lt "$required" ]; then
        log "Insufficient disk space. Required: ${required}KB, Available: ${available}KB" "ERROR"
        return 1
    fi
    return 0
}

detect_package_manager() {
    if [ -x /bin/opkg ] || [ -x /usr/bin/opkg ]; then
        PACKAGE_MANAGER="opkg"
    else
        log "Unsupported or missing package manager (only opkg supported on OpenWrt)" "ERROR"
        return 1
    fi
    log "Detected package manager: ${PACKAGE_MANAGER}" "INFO"
    return 0
}

detect_system() {
    if [ -f /etc/openwrt_release ]; then
        . /etc/openwrt_release
        ARCH="${DISTRIB_ARCH:-$(uname -m 2>/dev/null)}"
        OS_INFO="${DISTRIB_DESCRIPTION:-${DISTRIB_ID} ${DISTRIB_RELEASE}}"

        case "$DISTRIB_RELEASE" in
            *"21.02"*) BRANCH="openwrt-21.02" ;;
            *"22.03"*) BRANCH="openwrt-22.03" ;;
            *"23.05"*) BRANCH="openwrt-23.05" ;;
            *"24.10"*) BRANCH="openwrt-24.10" ;;
            "SNAPSHOT") BRANCH="SNAPSHOT" ;;
            *) BRANCH="${DISTRIB_RELEASE:-unknown}" ;;
        esac
    else
        ARCH=$(uname -m 2>/dev/null)
        OS_INFO="Unknown OpenWrt"
        BRANCH="unknown"
        log "Could not read /etc/openwrt_release, using basic detection" "WARNING"
    fi

    log "Detected: ${OS_INFO}" "INFO"
    log "Architecture: ${ARCH}" "INFO"
    log "Branch: ${BRANCH}" "INFO"
    return 0
}

install_package() {
    local package="$1"
    local max_retries=3
    local retry_count=0

    while [ $retry_count -lt $max_retries ]; do
        if opkg list-installed | grep -q "^${package} "; then
            log "Package already installed: ${package}" "INFO"
            return 0
        fi

        log "Installing: ${package}" "INFO"
        if opkg update >/dev/null 2>&1 && opkg install "$package" >/dev/null 2>&1; then
            log "Successfully installed: ${package}" "SUCCESS"
            return 0
        fi

        retry_count=$((retry_count + 1))
        log "Retrying installation (${retry_count}/${max_retries}): ${package}" "WARNING"
        sleep 2
    done

    log "Failed to install: ${package} after ${max_retries} attempts" "ERROR"
    return 1
}

backup_existing() {
    log "Checking for existing installation..." "INFO"

    if [ -d "/usr/share/rakitanmanager" ] || [ -d "/www/rakitanmanager" ] || [ -f "$CONFIG_FILE" ]; then
        log "Backing up existing installation..." "INFO"
        mkdir -p "$BACKUP_DIR"

        [ -d "/usr/share/rakitanmanager" ] && cp -r "/usr/share/rakitanmanager" "$BACKUP_DIR/" 2>/dev/null && log "Backed up /usr/share/rakitanmanager" "INFO"
        [ -d "/www/rakitanmanager" ] && cp -r "/www/rakitanmanager" "$BACKUP_DIR/" 2>/dev/null && log "Backed up /www/rakitanmanager" "INFO"
        [ -f "$CONFIG_FILE" ] && cp "$CONFIG_FILE" "$BACKUP_DIR/rakitanmanager.config" && log "Backed up configuration" "INFO"

        log "Backup completed at: ${BACKUP_DIR}" "SUCCESS"
    else
        log "No existing installation found to backup" "INFO"
    fi
}

restore_backup() {
    if [ -d "$BACKUP_DIR" ]; then
        log "Restoring backup..." "INFO"
        [ -d "$BACKUP_DIR/rakitanmanager" ] && cp -r "$BACKUP_DIR/rakitanmanager" "/usr/share/" 2>/dev/null && log "Restored /usr/share/rakitanmanager" "INFO"
        [ -f "$BACKUP_DIR/rakitanmanager.config" ] && cp "$BACKUP_DIR/rakitanmanager.config" "$CONFIG_FILE" && log "Restored configuration" "INFO"
        log "Backup restoration completed" "SUCCESS"
    fi
}

cleanup() {
    log "Cleaning up temporary files..." "INFO"
    rm -rf "$TEMP_DIR" 2>/dev/null
    log "Cleanup completed" "SUCCESS"
}

create_directories() {
    for dir in "/usr/share/rakitanmanager" "/www/rakitanmanager" "/etc/init.d" "$TEMP_DIR"; do
        [ ! -d "$dir" ] && mkdir -p "$dir" && log "Created directory: ${dir}" "INFO"
    done
}

set_permissions() {
    log "Setting permissions..." "INFO"
    find "/usr/share/rakitanmanager" -type f \( -name "*.sh" -o -name "*.py" \) -exec chmod +x {} \; 2>/dev/null
    chown -R root:root "/usr/share/rakitanmanager" "/www/rakitanmanager" 2>/dev/null
    [ -f "/etc/init.d/rakitanmanager" ] && chmod +x "/etc/init.d/rakitanmanager" 2>/dev/null
    log "Permissions set successfully" "SUCCESS"
}

validate_installation() {
    local errors=0
    log "Validating installation..." "INFO"

    for dir in "/usr/share/rakitanmanager" "/www/rakitanmanager"; do
        [ ! -d "$dir" ] && log "Missing directory: ${dir}" "ERROR" && errors=$((errors + 1))
    done

    [ ! -f "/www/rakitanmanager/index.php" ] && log "Missing web interface file" "ERROR" && errors=$((errors + 1))

    if [ $errors -eq 0 ]; then
        log "Installation validation passed!" "SUCCESS"
        return 0
    else
        log "Installation validation failed with ${errors} error(s)" "ERROR"
        return 1
    fi
}

show_summary() {
    echo -e "\n${CYAN}${BOLD}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║                     INSTALLATION SUMMARY                     "
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}\n"

    echo -e "${BOLD}System Information:${NC}\n"
    echo -e "  ${ARROW} OS: ${OS_INFO}"
    echo -e "  ${ARROW} Architecture: ${ARCH}"
    echo -e "  ${ARROW} Branch: ${BRANCH}"
    echo -e "  ${ARROW} Package Manager: ${PACKAGE_MANAGER}"

    echo -e "\n${BOLD}Installation Results:${NC}\n"
    echo -e "  ${GREEN}${CHECK_MARK} Successful steps: ${SUCCESS_STEPS}/${INSTALLATION_STEPS}"
    [ $FAILED_STEPS -gt 0 ] && echo -e "  ${RED}${CROSS_MARK} Failed steps: ${FAILED_STEPS}"

    echo -e "\n${BOLD}Installed Components:${NC}\n"
    echo -e "  ${ARROW} Core scripts: /usr/share/rakitanmanager/"
    echo -e "  ${ARROW} Web interface: /www/rakitanmanager/"
    [ -f "$CONFIG_FILE" ] && echo -e "  ${ARROW} Configuration: ${CONFIG_FILE}"
    [ -f "/etc/init.d/rakitanmanager" ] && echo -e "  ${ARROW} Init script: /etc/init.d/rakitanmanager"

    echo -e "\n${BOLD}Next Steps:${NC}\n"
    echo -e "  1. Access web interface: http://<your-router-ip>/rakitanmanager"
    echo -e "  2. Configure your modems in the web interface"
    echo -e "  3. Start the service: /etc/init.d/rakitanmanager start"
    echo -e "  4. Enable auto-start: /etc/init.d/rakitanmanager enable"

    if [ -d "$BACKUP_DIR" ]; then
        echo -e "\n${YELLOW}${BOLD}Note:${NC} Backup files are saved at: ${BACKUP_DIR}"
        echo -e "      You can remove them after confirming everything works."
    fi

    echo -e "\n${GREEN}${BOLD}Installation completed!${NC}\n\n"
}

# ============================================
# Main Installation Function
# ============================================

install_rakitanmanager() {
    > "$LOG_FILE"
    log "Starting RakitanManager-Reborn installation..." "INFO"

    # Step 1: System Check
    step_header 1 "System Check"
    (
        detect_system &&
        detect_package_manager &&
        check_internet &&
        check_disk_space 50000
    )
    check_success || log "Prerequisite check failed (continuing anyway)" "WARNING"

    # Step 2: Install Dependencies
    step_header 2 "Installing Dependencies"
    (
        if [ "$BRANCH" = "openwrt-21.02" ] || echo "$OS_INFO" | grep -q "21.02"; then
            php_pkgs="$PACKAGE_PHP7"
        else
            php_pkgs="$PACKAGE_PHP8"
        fi

        all_pkgs="$PACKAGE_BASE $php_pkgs $PACKAGE_PYTHON"
        for pkg in $all_pkgs; do
            install_package "$pkg" || true
        done
    )
    check_success || echo "${YELLOW}Continuing with partial dependencies...${NC}"

    # Step 3: Backup
    step_header 3 "Backup Existing Installation"
    backup_existing
    check_success

    # Step 4: Download
    step_header 4 "Downloading RakitanManager-Reborn"
    (
        create_directories
        if command -v git >/dev/null 2>&1; then
            log "Cloning repository instead..." "INFO"
            if git clone --depth 1 "$REPO_URL" "$TEMP_DIR" 2>/dev/null; then
                EXTRACTED_DIR="$TEMP_DIR"
                log "Cloned repository to: $EXTRACTED_DIR" "SUCCESS"
            else
                log "Git clone failed" "ERROR"
                exit 1
            fi
        else
            log "Git not available — cannot proceed" "ERROR"
            exit 1
        fi
    )
    check_success || { log "Download failed" "ERROR"; exit 1; }

    # Step 5: Install Files
    step_header 5 "Installing Files"
    (
        # Copy Python files
        cp -rf "$EXTRACTED_DIR/rakitanmanager/core/." "/usr/share/rakitanmanager/" 2>/dev/null
        log "Copied core files from $EXTRACTED_DIR/rakitanmanager/core" "INFO"

        cp -rf "$EXTRACTED_DIR/rakitanmanager/config/." "/etc/config/" 2>/dev/null
        log "Copied config files from $EXTRACTED_DIR/rakitanmanager/config" "INFO"

        cp -rf "$EXTRACTED_DIR/rakitanmanager/init.d/." "/etc/init.d/" 2>/dev/null
        log "Copied init.d files from $EXTRACTED_DIR/rakitanmanager/init.d" "INFO"

        cp -rf "$EXTRACTED_DIR/rakitanmanager/web/." "/www/rakitanmanager/" 2>/dev/null
        log "Copied web files from $EXTRACTED_DIR/rakitanmanager/web" "INFO"

        create_minimal_installation
        
        log "Files copied successfully" "SUCCESS"
    )
    check_success || log "File installation incomplete" "WARNING"

    # Steps 6-9
    step_header 6 "Restoring Configuration"
    restore_backup
    check_success

    step_header 7 "Setting Permissions"
    set_permissions
    check_success

    step_header 8 "Validating Installation"
    validate_installation
    check_success

    step_header 9 "Cleaning Up"
    cleanup
    check_success

    show_summary

    [ -f /etc/init.d/rakitanmanager ] && /etc/init.d/rakitanmanager enable && log "Service enabled" "SUCCESS"
    log "Installation process completed" "INFO"
}

create_minimal_installation() {
    mkdir -p /usr/lib/lua/luci/view /usr/lib/lua/luci/controller

    cat > /usr/lib/lua/luci/view/rakitanmanager.htm << 'EOF'
<%+header%>
<div class="cbi-map">
<iframe id="rakitanmanager" style="width: 100%; min-height: 750px; border: none; border-radius: 2px;"></iframe>
</div>
<script type="text/javascript">
document.getElementById("rakitanmanager").src = "http://" + window.location.hostname + "/rakitanmanager/index.php";
</script>
<%+footer%>
EOF

    cat > /usr/lib/lua/luci/controller/rakitanmanager.lua << 'EOF'
module("luci.controller.rakitanmanager", package.seeall)
function index()
    entry({"admin","modem","rakitanmanager"}, template("rakitanmanager"), _("Rakitan Manager"), 7).leaf=true
end
EOF
}

# ============================================
# Other Functions (Uninstall, Update, Repair, Menu)
# ============================================

uninstall_rakitanmanager() {
    echo -e "${RED}${BOLD}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║                    UNINSTALL WARNING                      ║"
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}\n"
    echo -e "${YELLOW}This will remove RakitanManager and all components.${NC}\n"
    echo -n "Type 'YES' to confirm: "
    read -r confirmation
    [ "$confirmation" != "YES" ] && { echo "${GREEN}Uninstall cancelled.${NC}"; exit 0; }

    backup_existing
    [ -f /etc/init.d/rakitanmanager ] && /etc/init.d/rakitanmanager stop 2>/dev/null && /etc/init.d/rakitanmanager disable 2>/dev/null
    rm -rf /usr/share/rakitanmanager /www/rakitanmanager
    rm -f /etc/config/rakitanmanager /etc/init.d/rakitanmanager
    rm -f /usr/lib/lua/luci/view/rakitanmanager.htm /usr/lib/lua/luci/controller/rakitanmanager.lua
    if command -v uci >/dev/null 2>&1; then
        uci delete rakitanmanager 2>/dev/null; uci commit 2>/dev/null
    fi
    echo "${GREEN}Uninstalled. Backup: $BACKUP_DIR${NC}"
}

update_rakitanmanager() {
    [ ! -d /usr/share/rakitanmanager ] && { echo "${RED}Not installed!${NC}"; exit 1; }
    echo -n "Update to latest version? (y/n): "; read -r r
    [ "$r" = "y" -o "$r" = "Y" ] || { echo "Cancelled."; exit 0; }
    install_rakitanmanager
}

repair_installation() {
    echo -n "Repair installation? (y/n): "; read -r r
    [ "$r" = "y" -o "$r" = "Y" ] || { echo "Cancelled."; exit 0; }
    detect_package_manager
    for pkg in $PACKAGE_BASE; do install_package "$pkg" || true; done
    set_permissions
    validate_installation
    echo "${GREEN}Repair completed!${NC}"
}

show_menu() {
    clear
    echo -e "${CYAN}╔═══════════════════════════════════════════════════════════╗"
    echo -e "║${BOLD}               OpenWrt Rakitan Manager Installer           ${CYAN}║"
    echo -e "║${BOLD}                     Installer Version 2.2                 ${CYAN}║"
    echo -e "╚═══════════════════════════════════════════════════════════╝${NC}"
    echo -e "\n"
    echo -e "${CYAN}${BOLD}Select an option:${NC}\n"
    echo -e "  ${GREEN}1${NC}. Install RakitanManager-Reborn (Fresh installation)\n"
    echo -e "  ${YELLOW}2${NC}. Update RakitanManager-Reborn (To latest version)\n"
    echo -e "  ${BLUE}3${NC}. Repair Installation (Fix issues)\n"
    echo -e "  ${RED}4${NC}. Uninstall RakitanManager-Reborn (Remove completely)\n"
    echo -e "  ${CYAN}5${NC}. Check System Compatibility\n"
    echo -e "  ${WHITE}6${NC}. View Installation Log\n"
    echo -e "  ${GRAY}0${NC}. Exit\n"
    echo -n "Enter your choice [0-6]: "
    read -r choice

    case "$choice" in
        1) install_rakitanmanager ;;
        2) update_rakitanmanager ;;
        3) repair_installation ;;
        4) uninstall_rakitanmanager ;;
        5)
            detect_system; detect_package_manager; check_internet
            echo -e "\n${GREEN}Compatibility check completed!${NC}\n"
            read -r _
            show_menu
            ;;
        6)
            clear; [ -f "$LOG_FILE" ] && cat "$LOG_FILE" || echo "No log file."
            read -r _; show_menu
            ;;
        0) echo "${GREEN}Goodbye!${NC}"; exit 0 ;;
        *) echo "${RED}Invalid option!${NC}"; sleep 1; show_menu ;;
    esac
}

# ============================================
# Entry Point
# ============================================

[ "$(id -u)" -ne 0 ] && { echo "${RED}Run as root!${NC}"; exit 1; }
mkdir -p "$TEMP_DIR"
trap cleanup EXIT
show_menu