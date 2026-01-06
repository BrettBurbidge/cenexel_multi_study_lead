#!/bin/bash

# Version Bumper - Updates version in both release.json and PHP file

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

PLUGIN_FILE="cenexel-location-leads.php"
RELEASE_FILE="release.json"

if [ $# -eq 0 ]; then
    echo -e "${YELLOW}Usage: ./bump-version.sh <new_version> [date]${NC}"
    echo "Example: ./bump-version.sh 0.4.6"
    echo "Example: ./bump-version.sh 0.4.6 2024-01-20"
    exit 1
fi

NEW_VERSION="$1"
NEW_DATE="${2:-$(date +%Y-%m-%d)}"

# Validate version format (basic check)
if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    echo -e "${YELLOW}Warning: Version format should be MAJOR.MINOR.PATCH (e.g., 0.4.6)${NC}"
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 0
    fi
fi

# Get current version
if command -v jq &> /dev/null; then
    CURRENT_VERSION=$(jq -r '.version' "$RELEASE_FILE")
else
    CURRENT_VERSION=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$RELEASE_FILE" | cut -d'"' -f4)
fi

echo -e "${BLUE}Updating version from ${CURRENT_VERSION} to ${NEW_VERSION}${NC}"

# Update release.json
if command -v jq &> /dev/null; then
    # Use jq for cleaner JSON update
    jq ".version = \"${NEW_VERSION}\" | .date = \"${NEW_DATE}\"" "$RELEASE_FILE" > "${RELEASE_FILE}.tmp" && mv "${RELEASE_FILE}.tmp" "$RELEASE_FILE"
else
    # Fallback: use sed
    sed -i.bak "s/\"version\": *\"[^\"]*\"/\"version\": \"${NEW_VERSION}\"/" "$RELEASE_FILE"
    sed -i.bak "s/\"date\": *\"[^\"]*\"/\"date\": \"${NEW_DATE}\"/" "$RELEASE_FILE"
    rm -f "${RELEASE_FILE}.bak"
fi

# Update PHP file
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS - use extended regex
    sed -i '' -E "s/Version: [0-9]+\.[0-9]+\.[0-9]+/Version: ${NEW_VERSION}/" "$PLUGIN_FILE"
else
    # Linux
    sed -i -E "s/Version: [0-9]+\.[0-9]+\.[0-9]+/Version: ${NEW_VERSION}/" "$PLUGIN_FILE"
fi

echo -e "${GREEN}✓ Version updated successfully!${NC}"
echo ""
echo "Updated files:"
echo "  - $RELEASE_FILE (version: $NEW_VERSION, date: $NEW_DATE)"
echo "  - $PLUGIN_FILE (version: $NEW_VERSION)"
echo ""
echo "Next step: Run ./create-release.sh to create the release package"
