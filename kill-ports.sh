#!/bin/bash

# 8000-8010番、5173-5182番ポートを使用しているプロセスを kill するスクリプト

echo "Checking ports 8000-8010 and 5173-5182..."

killed_count=0

for port in {8000..8010} {5173..5182}; do
    pids=$(lsof -ti:"$port" 2>/dev/null)
    if [ -z "$pids" ]; then
        continue
    fi

    echo "Port $port is in use by PID(s): $pids"
    for pid in $pids; do
        if kill -9 "$pid" 2>/dev/null; then
            echo "  ✓ Killed process $pid"
            killed_count=$((killed_count + 1))
        else
            echo "  ✗ Failed to kill process $pid"
        fi
    done
done

if [ "$killed_count" -eq 0 ]; then
    echo "No processes found on specified ports"
else
    echo ""
    echo "Total processes killed: $killed_count"
fi
