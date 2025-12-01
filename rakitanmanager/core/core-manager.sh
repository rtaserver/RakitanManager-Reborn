#!/bin/bash
# Copyright 2024 RTA SERVER

log_file="/usr/share/rakitanmanager/rakitanmanager.log"
exec 1>>"$log_file" 2>&1

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Ambil informasi perangkat
DEVICE_INFO=$(ubus call system board 2>/dev/null)
if [ $? -ne 0 ]; then
    log "Error: Tidak dapat mengambil informasi perangkat"
    exit 1
fi

DEVICE_PROCESSOR=$(echo "$DEVICE_INFO" | jq -r '.system // "unknown"')
DEVICE_MODEL=$(echo "$DEVICE_INFO" | jq -r '.model // "unknown"')
DEVICE_BOARD=$(echo "$DEVICE_INFO" | jq -r '.board_name // "unknown"')

RAKITANMANAGERDIR="/usr/share/rakitanmanager"

# Parsing konfigurasi dan menjalankan fungsi-fungsi yang diperlukan
json_file="/usr/share/rakitanmanager/modems.json"

map_modem_data() {
    local modem_data="$1"
    
    # Extract fields dari format baru
    local id=$(jq -r '.id' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local method=$(jq -r '.method' <<< "$modem_data")
    local host_ping=$(jq -r '.host_ping' <<< "$modem_data")
    local ping_device=$(jq -r '.ping_device' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")
    local delay_time=$(jq -r '.delay_time' <<< "$modem_data")
    local port_modem=$(jq -r '.port_modem' <<< "$modem_data")
    local interface_mm=$(jq -r '.interface_mm' <<< "$modem_data")
    local android_device=$(jq -r '.android_device' <<< "$modem_data")
    local renew_method=$(jq -r '.renew_method' <<< "$modem_data")
    local ip_modem=$(jq -r '.ip_modem' <<< "$modem_data")
    local username=$(jq -r '.username' <<< "$modem_data")
    local password=$(jq -r '.password' <<< "$modem_data")
    local custom_script=$(jq -r '.custom_script' <<< "$modem_data")
    
    # Map ke format yang diharapkan core-manager
    local mapped_data=$(jq -n \
        --arg id "$id" \
        --arg name "$name" \
        --arg device_name "$device_name" \
        --arg method "$method" \
        --arg host_ping "$host_ping" \
        --arg ping_device "$ping_device" \
        --arg retry_count "$retry_count" \
        --arg delay_time "$delay_time" \
        --arg port_modem "$port_modem" \
        --arg interface_mm "$interface_mm" \
        --arg android_device "$android_device" \
        --arg renew_method "$renew_method" \
        --arg ip_modem "$ip_modem" \
        --arg username "$username" \
        --arg password "$password" \
        --arg custom_script "$custom_script" \
        '{
            id: $id,
            name: $name,
            device_name: $device_name,
            method: $method,
            host_ping: $host_ping,
            ping_device: $ping_device,
            retry_count: $retry_count,
            delay_time: $delay_time,
            port_modem: $port_modem,
            interface_mm: $interface_mm,
            android_device: $android_device,
            renew_method: $renew_method,
            ip_modem: $ip_modem,
            username: $username,
            password: $password,
            custom_script: $custom_script
        }')
    
    echo "$mapped_data"
}

parse_config() {
    modems=()
    
    if [ ! -f "$json_file" ]; then
        log "File konfigurasi $json_file tidak ditemukan"
        return 1
    fi
    
    # Validasi JSON
    if ! jq empty "$json_file" 2>/dev/null; then
        log "Error: File JSON tidak valid"
        return 1
    fi
    
    while IFS= read -r line; do
        if [ -n "$line" ]; then
            mapped_data=$(map_modem_data "$line")
            if [ $? -eq 0 ] && [ -n "$mapped_data" ]; then
                modems+=("$mapped_data")
            else
                log "Error: Gagal memetakan data modem"
            fi
        fi
    done < <(jq -c '.[]' "$json_file")
}

perform_ping() {
    local modem_data="$1"
    local id name device_name method host_ping ping_device retry_count delay_time
    local port_modem interface_mm android_device renew_method ip_modem username
    local password custom_script
    
    id=$(jq -r '.id' <<< "$modem_data")
    name=$(jq -r '.name' <<< "$modem_data")
    device_name=$(jq -r '.device_name' <<< "$modem_data")
    method=$(jq -r '.method' <<< "$modem_data")
    host_ping=$(jq -r '.host_ping' <<< "$modem_data")
    ping_device=$(jq -r '.ping_device' <<< "$modem_data")
    retry_count=$(jq -r '.retry_count' <<< "$modem_data")
    delay_time=$(jq -r '.delay_time' <<< "$modem_data")
    port_modem=$(jq -r '.port_modem' <<< "$modem_data")
    interface_mm=$(jq -r '.interface_mm' <<< "$modem_data")
    android_device=$(jq -r '.android_device' <<< "$modem_data")
    renew_method=$(jq -r '.renew_method' <<< "$modem_data")
    ip_modem=$(jq -r '.ip_modem' <<< "$modem_data")
    username=$(jq -r '.username' <<< "$modem_data")
    password=$(jq -r '.password' <<< "$modem_data")
    custom_script=$(jq -r '.custom_script' <<< "$modem_data")

    delay_time=${delay_time:-1}
    retry_count=${retry_count:-3}

    local attempt=1
    local new_ip=""

    while true; do
        # Log rotation
        log_size=$(wc -c < "$log_file" 2>/dev/null || echo "0")
        max_size=$((2 * 10000))
        if [ "$log_size" -gt "$max_size" ]; then
            echo -n "" > "$log_file"
            log "Log dibersihkan karena melebihi ukuran maksimum."
        fi

        local status_Internet=false
        local ping_success=false

        # Split host_ping by space
        IFS=' ' read -ra hosts <<< "$host_ping"
        
        for pinghost in "${hosts[@]}"; do
            ping_success=false

            case "$method" in
                "ICMP")
                    if [ "$ping_device" = "default" ] || [ -z "$ping_device" ]; then
                        if ping -q -c 3 -W 3 "${pinghost}" > /dev/null 2>&1; then
                            log "[$device_name - $name] ICMP ping to $pinghost succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] ICMP ping to $pinghost failed"
                        fi
                    else
                        if ping -q -c 3 -W 3 -I "${ping_device}" "${pinghost}" > /dev/null 2>&1; then
                            log "[$device_name - $name] ICMP ping to $pinghost on interface $ping_device succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] ICMP ping to $pinghost on interface $ping_device failed"
                        fi
                    fi
                    ;;
                "CURL")
                    if [ "$ping_device" = "default" ] || [ -z "$ping_device" ]; then
                        if curl -s --max-time 3 "http://${pinghost}" > /dev/null 2>&1; then
                            log "[$device_name - $name] CURL ping to $pinghost succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] CURL ping to $pinghost failed"
                        fi
                    else
                        if curl --interface "${ping_device}" -s --max-time 3 "http://${pinghost}" > /dev/null 2>&1; then
                            log "[$device_name - $name] CURL ping to $pinghost on interface $ping_device succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] CURL ping to $pinghost on interface $ping_device failed"
                        fi
                    fi
                    ;;
                "HTTP")
                    if [ "$ping_device" = "default" ] || [ -z "$ping_device" ]; then
                        if curl -Is --max-time 3 "http://${pinghost}" >/dev/null 2>&1; then
                            log "[$device_name - $name] HTTP ping to $pinghost succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] HTTP ping to $pinghost failed"
                        fi
                    else
                        if curl --interface "${ping_device}" -Is --max-time 3 "http://${pinghost}" >/dev/null 2>&1; then
                            log "[$device_name - $name] HTTP ping to $pinghost on interface $ping_device succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] HTTP ping to $pinghost on interface $ping_device failed"
                        fi
                    fi
                    ;;
                "HTTP/S")
                    if [ "$ping_device" = "default" ] || [ -z "$ping_device" ]; then
                        if curl -kIs --max-time 3 "https://${pinghost}" >/dev/null 2>&1; then
                            log "[$device_name - $name] HTTPS ping to $pinghost succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] HTTPS ping to $pinghost failed"
                        fi
                    else
                        if curl --interface "${ping_device}" -kIs --max-time 3 "https://${pinghost}" >/dev/null 2>&1; then
                            log "[$device_name - $name] HTTPS ping to $pinghost on interface $ping_device succeeded"
                            ping_success=true
                        else
                            log "[$device_name - $name] HTTPS ping to $pinghost on interface $ping_device failed"
                        fi
                    fi
                    ;;
                *)
                    log "[$device_name - $name] Method $method tidak dikenali"
                    ;;
            esac

            if [ "$ping_success" = true ]; then
                status_Internet=true
                attempt=1
                break
            fi
        done

        if [ "$status_Internet" = false ]; then
            log "[$device_name - $name] Gagal PING | Attempt $attempt/$retry_count"
            
            if [ "$attempt" -ge "$retry_count" ]; then
                case $device_name in
                    "Rakitan")
                        handle_rakitan "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    "HP (Phone)")
                        handle_hp "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    "Huawei / Orbit")
                        handle_orbit "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    "Hilink")
                        handle_hilink "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    "MF90")
                        handle_mf90 "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    "Custom Script")
                        handle_customscript "$modem_data" "$attempt"
                        attempt=1
                        ;;
                    *)
                        log "[$device_name - $name] Device type tidak dikenali"
                        ;;
                esac
            fi
            
            attempt=$((attempt + 1))
        else
            attempt=1
        fi
        
        sleep "$delay_time"
    done
}

handle_rakitan() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local ping_device=$(jq -r '.ping_device' <<< "$modem_data")
    local port_modem=$(jq -r '.port_modem' <<< "$modem_data")
    local interface_mm=$(jq -r '.interface_mm' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Renew IP Started"
        if [ -f "$RAKITANMANAGERDIR/modem-rakitan.sh" ]; then
            "$RAKITANMANAGERDIR/modem-rakitan.sh" renew "$ping_device" "$port_modem" "$interface_mm"
        else
            log "Error: File modem-rakitan.sh tidak ditemukan"
        fi
    fi
}

handle_hp() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local android_device=$(jq -r '.android_device' <<< "$modem_data")
    local renew_method=$(jq -r '.renew_method' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Restart Network Started"
        if [ -f "$RAKITANMANAGERDIR/modem-hp.sh" ]; then
            case "$renew_method" in
                "modpesv1")
                    "$RAKITANMANAGERDIR/modem-hp.sh" "$android_device" restart v1
                    ;;
                "modpesv2")
                    "$RAKITANMANAGERDIR/modem-hp.sh" "$android_device" restart v2
                    ;;
                *)
                    "$RAKITANMANAGERDIR/modem-hp.sh" "$android_device" restart
                    ;;
            esac
        else
            log "Error: File modem-hp.sh tidak ditemukan"
        fi
    fi
}

handle_orbit() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local ip_modem=$(jq -r '.ip_modem' <<< "$modem_data")
    local username=$(jq -r '.username' <<< "$modem_data")
    local password=$(jq -r '.password' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Restart Network Started"
        if [ -f "$RAKITANMANAGERDIR/modem-orbit.py" ]; then
            python3 "$RAKITANMANAGERDIR/modem-orbit.py" "$ip_modem" "$username" "$password"
        else
            log "Error: File modem-orbit.py tidak ditemukan"
        fi
    fi
}

handle_hilink() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local ip_modem=$(jq -r '.ip_modem' <<< "$modem_data")
    local username=$(jq -r '.username' <<< "$modem_data")
    local password=$(jq -r '.password' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Restart Network Started"
        if [ -f "$RAKITANMANAGERDIR/modem-hilink.sh" ]; then
            "$RAKITANMANAGERDIR/modem-hilink.sh" "$ip_modem" "$username" "$password"
        else
            log "Error: File modem-hilink.sh tidak ditemukan"
        fi
    fi
}

handle_mf90() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local ip_modem=$(jq -r '.ip_modem' <<< "$modem_data")
    local username=$(jq -r '.username' <<< "$modem_data")
    local password=$(jq -r '.password' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Restart Network Started"
        if [ -f "$RAKITANMANAGERDIR/modem-mf90.sh" ]; then
            "$RAKITANMANAGERDIR/modem-mf90.sh" "$ip_modem" "$username" "$password" reboot
            "$RAKITANMANAGERDIR/modem-mf90.sh" "$ip_modem" "$username" "$password" disable_wifi
        else
            log "Error: File modem-mf90.sh tidak ditemukan"
        fi
    fi
}

handle_customscript() {
    local modem_data="$1"
    local attempt="$2"
    
    local device_name=$(jq -r '.device_name' <<< "$modem_data")
    local name=$(jq -r '.name' <<< "$modem_data")
    local custom_script=$(jq -r '.custom_script' <<< "$modem_data")
    local retry_count=$(jq -r '.retry_count' <<< "$modem_data")

    if [ "$attempt" -eq "$retry_count" ]; then
        log "[$device_name - $name] Gagal PING | Custom Script Started"
        if [ -n "$custom_script" ]; then
            local script_clean=$(echo "$custom_script" | tr -d '\r')
            bash -c "$script_clean"
        else
            log "Error: Custom script kosong"
        fi
    fi
}

main() {
    log "Starting RakitanManager..."
    parse_config
    if [ ${#modems[@]} -eq 0 ]; then
        log "Tidak ada modem yang dikonfigurasi"
        return 1
    fi
    
    for modem_data in "${modems[@]}"; do
        perform_ping "$modem_data" &
    done
}

rakitanmanager_stop() {
    local pids
    pids=$(pgrep -f "core-manager.sh" 2>/dev/null)
    
    if [ -n "$pids" ]; then
        kill $pids 2>/dev/null
        sleep 2
        # Force kill jika masih berjalan
        if pidof core-manager.sh >/dev/null; then
            /usr/share/rakitanmanager/core-manager.sh -k 2>/dev/null || {
            killall -9 core-manager.sh 2>/dev/null
        }
        fi
        log "RakitanManager Berhasil Dihentikan."
    else
        log "RakitanManager is not running."
    fi
    
    # Kill child processes juga
    pids=$(pgrep -P $$ 2>/dev/null)
    if [ -n "$pids" ]; then
        kill $pids 2>/dev/null
    fi
}

while getopts ":skrpcvh" rakitanmanager; do
    case $rakitanmanager in
        s)
            main
            ;;
        k)
            rakitanmanager_stop
            ;;
    esac
done
