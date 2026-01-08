#!/bin/bash

# CenExel Location Leads - Release Builder
# This script creates a zip file of the plugin for deployment

set -e  # Exit on error

# Cursor injects an invalid GITHUB_TOKEN - remove it to use gh keyring auth
if [ -n "${GITHUB_TOKEN:-}" ]; then
    echo "⚠️  Unsetting invalid GITHUB_TOKEN from environment..."
    unset GITHUB_TOKEN
fi

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if release.json exists
if [ ! -f "release.json" ]; then
    echo -e "${YELLOW}Error: release.json not found${NC}"
    echo "Please create a release.json file with release details."
    exit 1
fi

# Function to increment patch version (e.g., 0.8.0 -> 0.8.1)
increment_patch_version() {
    local version=$1
    local major=$(echo "$version" | cut -d. -f1)
    local minor=$(echo "$version" | cut -d. -f2)
    local patch=$(echo "$version" | cut -d. -f3)
    
    # Increment patch version
    patch=$((patch + 1))
    
    echo "${major}.${minor}.${patch}"
}

# Read current version from release.json (requires jq, but fallback to grep)
if command -v jq &> /dev/null; then
    CURRENT_VERSION=$(jq -r '.version' release.json)
    PLUGIN_SLUG=$(jq -r '.plugin_slug' release.json)
else
    # Fallback: use grep if jq is not available
    CURRENT_VERSION=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' release.json | cut -d'"' -f4)
    PLUGIN_SLUG=$(grep -o '"plugin_slug"[[:space:]]*:[[:space:]]*"[^"]*"' release.json | cut -d'"' -f4)
fi

if [ -z "$CURRENT_VERSION" ] || [ "$CURRENT_VERSION" = "null" ]; then
    echo -e "${YELLOW}Error: Could not read version from release.json${NC}"
    exit 1
fi

# Increment patch version
VERSION=$(increment_patch_version "$CURRENT_VERSION")
RELEASE_DATE=$(date +%Y-%m-%d)
VERSION_TAG="v${VERSION}"  # GitHub requires 'v' prefix for tags

echo -e "${BLUE}Version bump: ${CURRENT_VERSION} → ${GREEN}${VERSION}${NC} (Tag: ${VERSION_TAG})"

# Update release.json with new version and date
PLUGIN_FILE="cenexel-location-leads.php"
if command -v jq &> /dev/null; then
    jq ".version = \"${VERSION}\" | .date = \"${RELEASE_DATE}\"" release.json > release.json.tmp && mv release.json.tmp release.json
else
    # Fallback: use sed
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" release.json
        sed -i '' "s/\"date\": *\"[^\"]*\"/\"date\": \"${RELEASE_DATE}\"/" release.json
    else
        sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" release.json
        sed -i "s/\"date\": *\"[^\"]*\"/\"date\": \"${RELEASE_DATE}\"/" release.json
    fi
fi

# Update PHP file version
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS - use extended regex
    sed -i '' -E "s/Version: [0-9]+\.[0-9]+\.[0-9]+/Version: ${VERSION}/" "$PLUGIN_FILE"
else
    # Linux
    sed -i -E "s/Version: [0-9]+\.[0-9]+\.[0-9]+/Version: ${VERSION}/" "$PLUGIN_FILE"
fi

echo -e "${GREEN}✓ Updated version in release.json and ${PLUGIN_FILE}${NC}"
echo ""

# Store the project root directory
PROJECT_ROOT=$(pwd)

# Create releases directory if it doesn't exist
RELEASES_DIR="${PROJECT_ROOT}/releases"
mkdir -p "$RELEASES_DIR"

# Define zip filename
ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
ZIP_PATH="${RELEASES_DIR}/${ZIP_NAME}"

# Check if zip file already exists
if [ -f "$ZIP_PATH" ]; then
    echo -e "${YELLOW}Warning: ${ZIP_NAME} already exists${NC}"
    read -p "Do you want to overwrite it? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Release creation cancelled."
        exit 0
    fi
    rm "$ZIP_PATH"
fi

echo -e "${BLUE}Creating release: ${ZIP_NAME}${NC}"
echo ""

# Create a temporary directory for building the release
TEMP_DIR=$(mktemp -d)
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"

# Create plugin directory structure
mkdir -p "$PLUGIN_DIR"

# Copy files to temp directory (excluding files we don't want in the release)
echo "Copying plugin files..."

# Copy main PHP file
cp "cenexel-location-leads.php" "$PLUGIN_DIR/"

# Copy assets directory
cp -r "assets" "$PLUGIN_DIR/"

# Copy readme if it exists
if [ -f "readme.md" ]; then
    cp "readme.md" "$PLUGIN_DIR/"
fi

# Create zip file
echo "Creating zip archive..."
cd "$TEMP_DIR"
zip -r "$ZIP_NAME" "$PLUGIN_SLUG" -q

# Move zip to releases directory (using absolute path)
mv "$ZIP_NAME" "$ZIP_PATH"

# Clean up temp directory
cd "$PROJECT_ROOT"
rm -rf "$TEMP_DIR"

# Display release info
echo -e "${GREEN}✓ Release created successfully!${NC}"
echo ""
echo "File: ${ZIP_PATH}"
echo "Size: $(du -h "$ZIP_PATH" | cut -f1)"

# Optionally display release notes
if command -v jq &> /dev/null; then
    echo ""
    echo -e "${BLUE}Release Notes:${NC}"
    jq -r '.notes[]' release.json | sed 's/^/  - /'
fi

# Optionally publish to GitHub
echo ""
if ! command -v gh &> /dev/null; then
    echo -e "${YELLOW}GitHub CLI (gh) not found. Install it to publish releases automatically.${NC}"
    echo "Visit: https://cli.github.com/"
elif ! gh auth status 2>&1 | grep -q "Logged in"; then
    echo -e "${YELLOW}GitHub CLI not authenticated. Run 'gh auth login' to enable GitHub publishing.${NC}"
else
    read -p "Publish to GitHub? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${BLUE}Publishing to GitHub...${NC}"
        
        # Build release notes from JSON
        if command -v jq &> /dev/null; then
            RELEASE_NOTES=$(jq -r '.notes[] | "- \(.)"' release.json | tr '\n' '\r' | sed 's/\r/\\n/g')
        else
            RELEASE_NOTES="Release ${VERSION_TAG}"
        fi
        
        # Create GitHub release (with 'v' prefix)
        gh release create "${VERSION_TAG}" \
            "$ZIP_PATH" \
            --title "${VERSION_TAG}" \
            --notes "$RELEASE_NOTES" \
            --repo "${GITHUB_USERNAME:-brettburbidge}/${GITHUB_REPO:-cenexel_multi_study_lead}"
        
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✓ Published to GitHub!${NC}"
        else
            echo -e "${YELLOW}Warning: GitHub release creation failed${NC}"
        fi
    fi
fi

echo ""
echo -e "${GREEN}Done!${NC}"
