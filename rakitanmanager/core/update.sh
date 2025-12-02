#!/bin/sh

# ============================================
# RakitanManager-Reborn Auto Installer/Updater
# Optimized for PHP execution with progress tracking
# ============================================

# Configuration
REPO_URL="https://github.com/rtaserver-wrt/RakitanManager-Reborn"
TEMP_DIR="/tmp/rakitanmanager_install"
PROGRESS_FILE="/tmp/rakitanmanager_progress.json"
STATUS_FILE="/tmp/rakitanmanager_status.json"
LOG_FILE="/tmp/rakitanmanager_install.log"
CONFIG_FILE="/etc/config/rakitanmanager"

# Required packages
PACKAGE_BASE="coreutils-sleep curl git git-http python3-pip bc screen adb httping jq procps-ng-pkill unzip dos2unix"
PACKAGE_PHP7="php7-mod-curl php7-mod-session php7-mod-zip php7-cgi"
PACKAGE_PHP8="php8-mod-curl php8-mod-session php8-mod-zip php8-cgi"
PACKAGE_PYTHON="python3-requests python3-pip python3-setuptools"

# ============================================
# Progress Tracking Functions
# ============================================

init_progress() {
    cat > "$PROGRESS_FILE" << EOF
{
    "status": "running",
    "step": 0,
    "total_steps": 9,
    "current_step": "Starting installation",
    "percentage": 0,
    "message": "",
    "timestamp": "$(date +%s)",
    "log": []
}
EOF
}

update_progress() {
    local step="$1"
    local message="$2"
    local percentage="$3"
    local status="${4:-running}"
    
    # Update progress file
    cat > "$PROGRESS_FILE" << EOF
{
    "status": "$status",
    "step": $step,
    "total_steps": 9,
    "current_step": "$message",
    "percentage": $percentage,
    "message": "$message",
    "timestamp": "$(date +%s)",
    "log": []
}
EOF
}

log_message() {
    local message="$1"
    local level="${2:-INFO}"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    # Log to file
    echo "[$timestamp] [$level] $message" >> "$LOG_FILE"
    
    # Also update status if possible
    if [ -f "$PROGRESS_FILE" ]; then
        local temp_file="/tmp/rakitanmanager_temp.json"
        jq --arg ts "$timestamp" --arg lvl "$level" --arg msg "$message" \
           '.log += [{"timestamp": $ts, "level": $lvl, "message": $msg}]' \
           "$PROGRESS_FILE" > "$temp_file" && mv "$temp_file" "$PROGRESS_FILE"
    fi
}

# ============================================
# Core Installation Functions
# ============================================

detect_system() {
    log_message "Detecting system..."
    
    if [ -f /etc/openwrt_release ]; then
        . /etc/openwrt_release
        ARCH="${DISTRIB_ARCH:-$(uname -m)}"
        OS_INFO="${DISTRIB_DESCRIPTION:-${DISTRIB_ID} ${DISTRIB_RELEASE}}"
        
        case "$DISTRIB_RELEASE" in
            *"21.02"*) BRANCH="openwrt-21.02" ;;
            *"22.03"*) BRANCH="openwrt-22.03" ;;
            *"23.05"*) BRANCH="openwrt-23.05" ;;
            *"24.10"*) BRANCH="openwrt-24.10" ;;
            *) BRANCH="${DISTRIB_RELEASE}" ;;
        esac
    else
        ARCH=$(uname -m)
        OS_INFO="Unknown OpenWrt"
        BRANCH="unknown"
    fi
    
    log_message "System: $OS_INFO, Arch: $ARCH, Branch: $BRANCH"
}

install_packages() {
    log_message "Installing required packages..."
    
    if [ "$BRANCH" = "openwrt-21.02" ]; then
        php_pkgs="$PACKAGE_PHP7"
    else
        php_pkgs="$PACKAGE_PHP8"
    fi
    
    all_pkgs="$PACKAGE_BASE $php_pkgs $PACKAGE_PYTHON"
    
    for pkg in $all_pkgs; do
        if opkg list-installed | grep -q "^$pkg "; then
            log_message "Package already installed: $pkg"
            continue
        fi
        
        log_message "Installing: $pkg"
        if opkg update >/dev/null 2>&1 && opkg install "$pkg" >/dev/null 2>&1; then
            log_message "✓ Installed: $pkg"
        else
            log_message "✗ Failed to install: $pkg" "WARNING"
        fi
        sleep 1
    done
}

download_repository() {
    log_message "Downloading latest version..."
    
    # Clean temp directory
    rm -rf "$TEMP_DIR"
    mkdir -p "$TEMP_DIR"
    
    # Try git first
    if command -v git >/dev/null 2>&1; then
        log_message "Using git to clone repository..."
        if git clone --depth 1 "$REPO_URL" "$TEMP_DIR" 2>&1 | tee -a "$LOG_FILE"; then
            log_message "Repository cloned successfully"
            return 0
        fi
    fi
    
    # Fallback: download zip
    log_message "Trying alternative download method..."
    if command -v curl >/dev/null 2>&1; then
        if curl -sL "$REPO_URL/archive/main.tar.gz" | tar -xz -C "$TEMP_DIR" --strip-components=1; then
            log_message "Downloaded via curl"
            return 0
        fi
    fi
    
    log_message "All download methods failed" "ERROR"
    return 1
}

install_files() {
    log_message "Installing files..."
    
    # Stop service if running
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        if pidof core-manager.sh >/dev/null; then
            killall -9 core-manager.sh 2>/dev/null || true
            log_message "Terminated running core-manager.sh process" "INFO"
        fi
    fi

    # Backup existing config modems.json
    if [ -f "/usr/share/rakitanmanager/modems.json" ]; then
        cp -f /usr/share/rakitanmanager/modems.json "$TEMP_DIR/rakitanmanager/config/modems.json" 2>/dev/null
        log_message "Backed up existing modems.json configuration"
    fi

    if [ -d "/usr/share/rakitanmanager" ]; then
        rm -rf /usr/share/rakitanmanager 2>/dev/null
    fi
    if [ -d "/www/rakitanmanager" ]; then
        rm -rf /www/rakitanmanager 2>/dev/null
    fi
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        rm -f /etc/init.d/rakitanmanager 2>/dev/null
    fi
    if [ -f "$CONFIG_FILE" ]; then
        rm -f "$CONFIG_FILE" 2>/dev/null
    fi
    
    # Create directories
    mkdir -p /usr/share/rakitanmanager /www/rakitanmanager /etc/init.d
    
    # Copy files from temp directory
    if [ -d "$TEMP_DIR/rakitanmanager" ]; then
        # Core files
        cp -rf "$TEMP_DIR/rakitanmanager/core/." /usr/share/rakitanmanager/ && \
        log_message "Installed core files"
        
        # Web interface
        cp -rf "$TEMP_DIR/rakitanmanager/web/." /www/rakitanmanager/ && \
        log_message "Installed web interface"
        
        # Init script
        cp -f "$TEMP_DIR/rakitanmanager/init.d/rakitanmanager" /etc/init.d/ && \
        log_message "Installed init script"
        
        # Config file (only if doesn't exist)
        if [ ! -f "$CONFIG_FILE" ] && [ -f "$TEMP_DIR/rakitanmanager/config/rakitanmanager" ]; then
            cp -f "$TEMP_DIR/rakitanmanager/config/rakitanmanager" "$CONFIG_FILE" && \
            log_message "Installed config file"
        fi
    fi

    # Restore modems.json if backed up
    if [ -f "$TEMP_DIR/rakitanmanager/config/modems.json" ]; then
        rm -f "/usr/share/rakitanmanager/modems.json" 2>/dev/null
        cp -f "$TEMP_DIR/rakitanmanager/config/modems.json" "/usr/share/rakitanmanager/modems.json" 2>/dev/null
        log_message "Restored modems.json configuration"
    fi
    
    # Set permissions
    chmod +x /etc/init.d/rakitanmanager 2>/dev/null
    find /usr/share/rakitanmanager -name "*.sh" -exec chmod +x {} \; 2>/dev/null
    find /usr/share/rakitanmanager -name "*.py" -exec chmod +x {} \; 2>/dev/null
    log_message "Set permissions"
}

create_luci_integration() {
    log_message "Creating LuCI integration..."
    
    mkdir -p /usr/lib/lua/luci/view /usr/lib/lua/luci/controller
    
    # View file
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
    
    # Controller file
    cat > /usr/lib/lua/luci/controller/rakitanmanager.lua << 'EOF'
module("luci.controller.rakitanmanager", package.seeall)
function index()
    entry({"admin","modem","rakitanmanager"}, template("rakitanmanager"), _("Rakitan Manager"), 7).leaf=true
end
EOF
    
    log_message "LuCI integration created"
}

start_service() {
    log_message "Starting service..."
    
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        /etc/init.d/rakitanmanager enable 2>/dev/null
        log_message "Service enabled"
    fi
}

validate_installation() {
    log_message "Validating installation..."
    local errors=0
    
    if [ ! -d "/usr/share/rakitanmanager" ]; then
        log_message "Missing /usr/share/rakitanmanager" "ERROR"
        errors=$((errors+1))
    fi
    
    if [ ! -d "/www/rakitanmanager" ]; then
        log_message "Missing /www/rakitanmanager" "ERROR"
        errors=$((errors+1))
    fi
    
    if [ ! -f "/www/rakitanmanager/index.php" ]; then
        log_message "Missing index.php" "ERROR"
        errors=$((errors+1))
    fi
    
    if [ $errors -eq 0 ]; then
        log_message "✓ Validation passed"
        return 0
    else
        log_message "✗ Validation failed with $errors errors" "ERROR"
        return 1
    fi
}

cleanup() {
    log_message "Cleaning up temporary files..."
    rm -rf "$TEMP_DIR" 2>/dev/null
    log_message "Cleanup completed"
}

# ============================================
# Main Installation Process
# ============================================

main() {
    log_message "=== Starting RakitanManager Installation/Update ==="
    
    # Initialize progress tracking
    init_progress
    
    # Step 1: System detection (11%)
    update_progress 1 "Detecting system" 11
    detect_system
    
    # Step 2: Install packages (22%)
    update_progress 2 "Installing dependencies" 22
    install_packages
    
    # Step 3: Download (33%)
    update_progress 3 "Downloading repository" 33
    if ! download_repository; then
        update_progress 3 "Download failed" 33 "failed"
        exit 1
    fi
    
    # Step 4: Install files (44%)
    update_progress 4 "Installing files" 44
    install_files
    
    # Step 5: LuCI integration (55%)
    update_progress 5 "Creating LuCI integration" 55
    create_luci_integration
    
    # Step 6: Validate (66%)
    update_progress 6 "Validating installation" 66
    validate_installation
    
    # Step 7: Start service (77%)
    update_progress 7 "Starting service" 77
    start_service
    
    # Step 8: Cleanup (100%)
    update_progress 8 "Cleaning up" 88
    cleanup
    
    # Final status
    update_progress 9 "Installation completed successfully" 100 "completed"
    log_message "=== Installation completed successfully ==="
    
    # Create completion marker
    echo "COMPLETED: $(date)" > "/tmp/rakitanmanager_install_complete"
}

# ============================================
# Execution
# ============================================

# Run main function and capture output
main 2>&1 | tee -a "$LOG_FILE"