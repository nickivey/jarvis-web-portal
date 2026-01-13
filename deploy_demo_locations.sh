#!/bin/bash
# Deploy demo location history to production server via FTP
# 
# Usage:
#   ./deploy_demo_locations.sh jjj@jarvis.nickivey.com password
#   ./deploy_demo_locations.sh jjj@jarvis.nickivey.com password --no-import
#   ./deploy_demo_locations.sh jjj@jarvis.nickivey.com password --import-only
#
# Environment variables:
#   FTP_HOST (default: ftp.nickivey.com)
#   FTP_PORT (default: 21)

set -e

FTP_USER="${1:-jjj@jarvis.nickivey.com}"
FTP_PASS="${2:-%)?66AEa3Fw{ijAr}"
FTP_HOST="${FTP_HOST:-ftp.nickivey.com}"
FTP_PORT="${FTP_PORT:-21}"
MODE="${3:-both}"  # 'both', 'deploy-only', 'import-only'

if [ "$FTP_USER" = "--help" ] || [ "$FTP_USER" = "-h" ]; then
    echo "Deploy demo location history scripts to production server"
    echo ""
    echo "Usage: ./deploy_demo_locations.sh [FTP_USER] [FTP_PASS] [MODE]"
    echo ""
    echo "Arguments:"
    echo "  FTP_USER         FTP username (default: jjj@jarvis.nickivey.com)"
    echo "  FTP_PASS         FTP password (default: %)?66AEa3Fw{ijAr)"
    echo "  MODE             'both' (default), 'deploy-only', or 'import-only'"
    echo ""
    echo "Environment variables:"
    echo "  FTP_HOST         FTP hostname (default: ftp.nickivey.com)"
    echo "  FTP_PORT         FTP port (default: 21)"
    echo ""
    echo "Examples:"
    echo "  # Deploy scripts and run import on server"
    echo "  ./deploy_demo_locations.sh"
    echo ""
    echo "  # Just upload the scripts (don't run import yet)"
    echo "  ./deploy_demo_locations.sh jjj@jarvis.nickivey.com password deploy-only"
    echo ""
    echo "  # Just run import on already-deployed scripts"
    echo "  ./deploy_demo_locations.sh jjj@jarvis.nickivey.com password import-only"
    exit 0
fi

echo "🚀 Demo Location History Deployment"
echo "=================================="
echo "FTP Server: $FTP_HOST:$FTP_PORT"
echo "FTP User: $FTP_USER"
echo "Deploy Mode: $MODE"
echo ""

# Deploy to FTP
if [ "$MODE" != "import-only" ]; then
    echo "📤 Uploading scripts to FTP server..."
    
    lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" << EOF
set ftp:ssl-allow no
cd scripts
put import_demo_locations.php
cd ../sql
put demo_location_history.sql
cd ..
put LOCATION_HISTORY_SETUP.md
bye
EOF
    
    echo "✓ Scripts uploaded successfully"
    echo ""
fi

# Run import on server (if SSH available)
if [ "$MODE" != "deploy-only" ]; then
    echo "📍 Running location history import on server..."
    echo ""
    echo "To import locations on your server, run:"
    echo ""
    echo "  SSH Method:"
    echo "  ssh -u YOUR_USER 'php /home/YOUR_HOST/public_html/scripts/import_demo_locations.php'"
    echo ""
    echo "  Direct Method:"
    echo "  cd /home/YOUR_HOST/public_html"
    echo "  php scripts/import_demo_locations.php"
    echo ""
    echo "  Or with custom user:"
    echo "  php scripts/import_demo_locations.php --user-id=47 --clear"
    echo ""
fi

echo "=================================="
echo "✅ Deployment complete!"
echo ""
echo "Next steps:"
echo "1. Connect to your server via SSH or FTP"
echo "2. Navigate to the application directory"
echo "3. Run: php scripts/import_demo_locations.php"
echo "4. Visit: https://your-site.com/public/location_history.php"
echo ""
echo "For more info, see: LOCATION_HISTORY_SETUP.md"
