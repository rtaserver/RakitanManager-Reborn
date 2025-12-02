#!/bin/sh

# ============================================
# RakitanManager-Reborn Auto Installer/Updater
# Optimized for PHP execution with progress tracking
# ============================================

# Configuration
REPO="rtaserver-wrt/RakitanManager-Reborn"
TEMP_DIR="/tmp/rakitanmanager_install"
PROGRESS_FILE="/tmp/rakitanmanager_progress.json"
STATUS_FILE="/tmp/rakitanmanager_status.json"
LOG_FILE="/tmp/rakitanmanager_install.log"
CONFIG_FILE="/etc/config/rakitanmanager"

# Package lists for different package managers
PACKAGE_BASE="coreutils-sleep curl git git-http python3-pip bc screen adb httping jq procps-ng-pkill unzip dos2unix"
PACKAGE_PHP7="php7-mod-curl php7-mod-session php7-mod-zip php7-cgi"
PACKAGE_PHP8="php8-mod-curl php8-mod-session php8-mod-zip php8-cgi"
PACKAGE_PYTHON="python3-requests python3-pip python3-setuptools"

# Global variables
ARCH=""
BRANCH=""
PACKAGE_MANAGER=""
OS_INFO=""
SYSTEM_TYPE=""

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
    if [ -f "$PROGRESS_FILE" ] && command -v jq >/dev/null 2>&1; then
        local temp_file="/tmp/rakitanmanager_temp.json"
        jq --arg ts "$timestamp" --arg lvl "$level" --arg msg "$message" \
           '.log += [{"timestamp": $ts, "level": $lvl, "message": $msg}]' \
           "$PROGRESS_FILE" > "$temp_file" 2>/dev/null && mv "$temp_file" "$PROGRESS_FILE" 2>/dev/null
    fi
}

# ============================================
# System Detection Functions
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
        OS_INFO="Unknown System"
        BRANCH="unknown"
        SYSTEM_TYPE="unknown"
    fi
    
    log_message "System: $OS_INFO, Arch: $ARCH, Branch: $BRANCH, Type: $SYSTEM_TYPE"
}

detect_package_manager() {
    if [ -x "/bin/opkg" ] || [ -x "/usr/bin/opkg" ]; then
        PACKAGE_MANAGER="opkg"
        SYSTEM_TYPE="openwrt_stable"
        log_message "Detected package manager: opkg"
        return 0
    elif [ -x "/usr/bin/apk" ]; then
        PACKAGE_MANAGER="apk"
        log_message "Detected package manager: apk"
        SYSTEM_TYPE="openwrt_snapshot"
        return 0
    else
        SYSTEM_TYPE="unknown"
        log_message "Unsupported or missing package manager" "ERROR"
        return 1
    fi
}

# ============================================
# Package Management Functions
# ============================================

get_system_packages() {
    case $PACKAGE_MANAGER in
        "opkg")
            if [ "$BRANCH" = "openwrt-21.02" ]; then
                echo "$PACKAGE_BASE $PACKAGE_PHP7 $PACKAGE_PYTHON"
            else
                echo "$PACKAGE_BASE $PACKAGE_PHP8 $PACKAGE_PYTHON"
            fi
            ;;
        "apk")
            echo "$PACKAGE_BASE $PACKAGE_PHP $PACKAGE_PYTHON"
            ;;
        *)
            echo ""
            ;;
    esac
}

install_package() {
    local package="$1"
    
    case $PACKAGE_MANAGER in
        "opkg")
            if opkg list-installed | grep -q "^$package "; then
                log_message "Package already installed: $package"
                return 0
            fi
            
            log_message "Installing: $package"
            if opkg update >/dev/null 2>&1 && opkg install "$package" >/dev/null 2>&1; then
                log_message "✓ Installed: $package"
                return 0
            else
                log_message "✗ Failed to install: $package" "WARNING"
                return 1
            fi
            ;;
        "apk")
            if apk info -e "$package" >/dev/null 2>&1; then
                log_message "Package already installed: $package"
                return 0
            fi
            
            log_message "Installing: $package"
            if apk add "$package" >/dev/null 2>&1; then
                log_message "✓ Installed: $package"
                return 0
            else
                log_message "✗ Failed to install: $package" "WARNING"
                return 1
            fi
            ;;
        *)
            log_message "Unknown package manager" "ERROR"
            return 1
            ;;
    esac
}

install_packages() {
    log_message "Installing required packages for $PACKAGE_MANAGER..."
    
    # Get package list
    local packages
    packages=$(get_system_packages)
    
    if [ -z "$packages" ]; then
        log_message "No packages defined for $PACKAGE_MANAGER" "WARNING"
        return 1
    fi
    
    log_message "Packages to install: $packages"
    
    # Update package manager
    case $PACKAGE_MANAGER in
        "opkg")
            opkg update >/dev/null 2>&1 || log_message "Failed to update opkg" "WARNING"
            ;;
        "apk")
            apk update >/dev/null 2>&1 || log_message "Failed to update apk" "WARNING"
            ;;
    esac
    
    # Install each package
    local failed=0
    for pkg in $packages; do
        if ! install_package "$pkg"; then
            failed=$((failed + 1))
        fi
        sleep 0.5
    done
    
    if [ $failed -eq 0 ]; then
        log_message "✓ All packages installed successfully"
        return 0
    else
        log_message "⚠ $failed package(s) failed to install" "WARNING"
        return 1
    fi
}

# ============================================
# Download Functions (GitHub Releases)
# ============================================

get_latest_tag() {
    log_message "Checking latest release tag for $REPO..."
    
    local tag=""
    
    if [ -z "$tag" ]; then
        tag=$(curl -s -I "https://github.com/$REPO/releases/latest" 2>/dev/null | \
              grep -i '^location:' | \
              sed 's|.*/tag/||' | \
              tr -d '\r')
    fi
    
    if [ -n "$tag" ]; then
        log_message "Latest release tag: $tag"
        echo "$tag"
        return 0
    else
        log_message "Could not determine latest release tag" "ERROR"
        return 1
    fi
}

download_release() {
    log_message "Downloading latest release..."
    
    # Get latest tag
    LATEST_TAG=$(get_latest_tag)
    if [ -z "$LATEST_TAG" ]; then
        log_message "Failed to get latest release tag" "ERROR"
        return 1
    fi
    
    local filename="RakitanManager-Reborn-$LATEST_TAG.zip"
    local download_url="https://github.com/$REPO/archive/refs/tags/$LATEST_TAG.zip"
    
    # Clean temp directory
    rm -rf "$TEMP_DIR" 2>/dev/null
    mkdir -p "$TEMP_DIR"
    
    log_message "Download URL: $download_url"
    
    # Download with appropriate tool
    if command -v wget >/dev/null 2>&1; then
        log_message "Using wget to download..."
        if wget -q --show-progress -O "/tmp/$filename" "$download_url" 2>&1 | tee -a "$LOG_FILE"; then
            log_message "✓ Download completed"
        else
            log_message "✗ Download failed with wget" "ERROR"
            return 1
        fi
    elif command -v curl >/dev/null 2>&1; then
        log_message "Using curl to download..."
        if curl -L --progress-bar -o "/tmp/$filename" "$download_url" 2>&1 | tee -a "$LOG_FILE"; then
            log_message "✓ Download completed"
        else
            log_message "✗ Download failed with curl" "ERROR"
            return 1
        fi
    else
        log_message "No download tool available (wget or curl)" "ERROR"
        return 1
    fi
    
    # Verify and extract
    if [ -f "/tmp/$filename" ]; then
        # Verify ZIP file
        if command -v unzip >/dev/null 2>&1; then
            if unzip -t "/tmp/$filename" >/dev/null 2>&1; then
                log_message "✓ ZIP file verification passed"
            else
                log_message "✗ ZIP file appears to be corrupted" "ERROR"
                return 1
            fi
        fi
        
        # Extract ZIP
        log_message "Extracting files..."
        if unzip -q "/tmp/$filename" -d "$TEMP_DIR" 2>&1 | tee -a "$LOG_FILE"; then
            log_message "✓ Files extracted successfully"
            
            # Find extracted directory
            EXTRACTED_DIR=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "*RakitanManager-Reborn*" 2>/dev/null | head -1)
            if [ -n "$EXTRACTED_DIR" ] && [ -d "$EXTRACTED_DIR" ]; then
                log_message "Extracted to: $EXTRACTED_DIR"
                return 0
            else
                log_message "Could not find extracted directory" "ERROR"
                return 1
            fi
        else
            log_message "✗ Failed to extract ZIP file" "ERROR"
            return 1
        fi
    else
        log_message "✗ Download file not found" "ERROR"
        return 1
    fi
}

# ============================================
# Core Installation Functions
# ============================================

install_files() {
    log_message "Installing files..."

    # Stop service if running
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        /etc/init.d/rakitanmanager stop 2>/dev/null
        log_message "Stopped service for installation"
    fi
    
    # Create directories
    mkdir -p /usr/share/rakitanmanager /www/rakitanmanager /etc/init.d
    
    # Find extracted directory
    local extracted_dir
    extracted_dir=$(find "$TEMP_DIR" -maxdepth 1 -type d -name "*RakitanManager-Reborn*" 2>/dev/null | head -1)
    
    if [ -z "$extracted_dir" ] || [ ! -d "$extracted_dir" ]; then
        log_message "Extracted directory not found" "ERROR"
        return 1
    fi
    
    log_message "Installing from: $extracted_dir"
    
    # Check directory structure
    if [ -d "$extracted_dir/rakitanmanager" ]; then
        # New structure with rakitanmanager subdirectory
        source_dir="$extracted_dir/rakitanmanager"
    else
        # Old structure
        source_dir="$extracted_dir"
    fi
    
    # Copy core files
    if [ -d "/usr/share/rakitanmanager" ]; then
        rm -rf /usr/share/rakitanmanager/* 2>/dev/null
        log_message "Cleared existing core files in /usr/share/rakitanmanager"
    fi
    if [ -d "$source_dir/core" ]; then
        cp -rf "$source_dir/core/." /usr/share/rakitanmanager/ 2>/dev/null && \
        log_message "Installed core files"
    fi
    
    # Copy web interface
    if [ -d "/www/rakitanmanager" ]; then
        rm -rf /www/rakitanmanager/* 2>/dev/null
        log_message "Cleared existing web interface files in /www/rakitanmanager"
    fi
    if [ -d "$source_dir/web" ]; then
        cp -rf "$source_dir/web/." /www/rakitanmanager/ 2>/dev/null && \
        log_message "Installed web interface"
    fi
    
    # Copy init script
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        rm -f /etc/init.d/rakitanmanager 2>/dev/null
        log_message "Removed existing init script"
    fi
    if [ -f "$source_dir/init.d/rakitanmanager" ]; then
        cp -f "$source_dir/init.d/rakitanmanager" /etc/init.d/ 2>/dev/null && \
        log_message "Installed init script"
    fi
    
    # Copy config file
    if [ -f "$CONFIG_FILE" ]; then
        rm -f "$CONFIG_FILE" 2>/dev/null
        log_message "Removed existing configuration file: $CONFIG_FILE"
    fi
    if [ -f "$source_dir/config/rakitanmanager" ]; then
        cp -f "$source_dir/config/rakitanmanager" "$CONFIG_FILE" 2>/dev/null && \
        log_message "Installed config file"
    fi
    
    # Set permissions
    chmod +x /etc/init.d/rakitanmanager 2>/dev/null
    find /usr/share/rakitanmanager -name "*.sh" -exec chmod +x {} \; 2>/dev/null
    find /usr/share/rakitanmanager -name "*.py" -exec chmod +x {} \; 2>/dev/null
    
    log_message "✓ Files installed and permissions set"
}

create_luci_integration() {
    log_message "Creating LuCI integration..."

    # Check if LuCI is available
    if ! command -v uci >/dev/null 2>&1; then
        log_message "LuCI not detected, skipping integration"
        return 0
    fi
    
    mkdir -p /usr/lib/lua/luci/view /usr/lib/lua/luci/controller 2>/dev/null
    
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
    
    log_message "✓ LuCI integration created"
}

start_service() {
    log_message "Starting service..."
    
    if [ -f "/etc/init.d/rakitanmanager" ]; then
        /etc/init.d/rakitanmanager enable 2>/dev/null
        log_message "✓ Service enabled at startup"
    else
        log_message "⚠ Init script not found" "WARNING"
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
    
    # Remove temp directory
    rm -rf "$TEMP_DIR" 2>/dev/null
    
    # Remove downloaded ZIP file
    rm -f /tmp/RakitanManager-Reborn-*.zip 2>/dev/null
    
    log_message "✓ Cleanup completed"
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
    detect_package_manager
    
    # Step 2: Install packages (22%)
    update_progress 2 "Installing dependencies" 22
    install_packages
    
    # Step 3: Download latest release (33%)
    update_progress 3 "Downloading latest release" 33
    if ! download_release; then
        update_progress 3 "Download failed" 33 "failed"
        log_message "=== Installation failed at download step ==="
        exit 1
    fi
    
    # Step 4: Install files (44%)
    update_progress 4 "Installing files" 44
    if ! install_files; then
        update_progress 4 "File installation failed" 44 "failed"
        log_message "=== Installation failed at file installation ==="
        exit 1
    fi
    
    # Step 5: LuCI integration (55%)
    update_progress 5 "Creating LuCI integration" 55
    create_luci_integration
    
    # Step 6: Validate (66%)
    update_progress 6 "Validating installation" 66
    if ! validate_installation; then
        update_progress 6 "Validation failed" 66 "failed"
        log_message "=== Installation failed validation ==="
        exit 1
    fi
    
    # Step 7: Start service (77%)
    update_progress 7 "Starting service" 77
    start_service
    
    # Step 8: Cleanup (100%)
    update_progress 8 "Cleaning up" 100
    cleanup
    
    # Final status
    update_progress 8 "Installation completed successfully" 100 "completed"
    log_message "=== Installation completed successfully ==="
    
    # Create completion marker
    echo "COMPLETED: $(date)" > "/tmp/rakitanmanager_install_complete" 2>/dev/null
    
    return 0
}

# ============================================
# Execution
# ============================================

# Run main function and capture output
main 2>&1 | tee -a "$LOG_FILE"

# Exit with appropriate code
if [ -f "/tmp/rakitanmanager_install_complete" ]; then
    exit 0
else
    exit 1
fi