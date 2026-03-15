#!/usr/bin/env bash
#
# MemoryGraph quick-install script
# Installs or uses existing XAMPP/Apache+PHP, starts the server, and prepares the app.
# Run with: bash install.sh   or   curl -sSL <repo-url>/install.sh | bash
#
set -e

REPO_URL="${MEMORYGRAPH_REPO_URL:-https://github.com/ZakRowton/LivingAIMemoryDashboard.git}"
APP_NAME="MemoryGraph"
CLONE_DIR_NAME="LivingAIMemoryDashboard"
DOCROOT_APP_DIR=""
INSTALL_DIR=""
XAMPP_FOUND=""
PHP_CMD=""
HTTPD_STARTED=""
APP_SUBPATH=""
HTDOCS=""

# --- helpers ---
log()  { echo "[MemoryGraph] $*"; }
warn() { echo "[MemoryGraph] WARNING: $*" >&2; }
err()  { echo "[MemoryGraph] ERROR: $*" >&2; }

detect_os() {
  case "$(uname -s)" in
    Linux*)   echo "linux";;
    Darwin*) echo "macos";;
    MINGW*|MSYS*|CYGWIN*) echo "windows";;
    *)       echo "unknown";;
  esac
}

# --- find or install XAMPP / Apache+PHP ---
find_xampp_linux() {
  if [[ -x /opt/lampp/lampp ]]; then
    echo "/opt/lampp"
    return
  fi
  return 1
}

find_xampp_macos() {
  if [[ -d /Applications/XAMPP ]]; then
    echo "/Applications/XAMPP"
    return
  fi
  return 1
}

find_xampp_windows() {
  local d
  for d in "/c/xampp" "C:/xampp" "/d/xampp" "D:/xampp"; do
    if [[ -d "$d" && ( -f "$d/apache/bin/httpd.exe" || -f "$d/apache_start.bat" ) ]]; then
      echo "$d"
      return
    fi
  done
  # Check Windows env
  if [[ -n "$XAMPP_HOME" && -d "$XAMPP_HOME" ]]; then
    echo "$XAMPP_HOME"
    return
  fi
  return 1
}

start_xampp_linux() {
  local xampp="$1"
  log "Starting XAMPP (Apache) at $xampp ..."
  if [[ -x "$xampp/lampp" ]]; then
    sudo "$xampp/lampp" startapache 2>/dev/null || "$xampp/lampp" startapache 2>/dev/null || true
    HTTPD_STARTED=1
  fi
}

start_xampp_macos() {
  local xampp="$1"
  log "Starting XAMPP at $xampp ..."
  if [[ -x "$xampp/xamppfiles/xampp" ]]; then
    sudo "$xampp/xamppfiles/xampp" startapache 2>/dev/null || true
    HTTPD_STARTED=1
  fi
}

start_xampp_windows() {
  local xampp="$1"
  log "Starting XAMPP Apache at $xampp ..."
  if [[ -f "$xampp/apache_start.bat" ]]; then
    cmd //c "\"$xampp\\apache_start.bat\"" 2>/dev/null || true
    HTTPD_STARTED=1
  elif [[ -f "$xampp/xampp_start.exe" ]]; then
    start "$xampp/xampp_start.exe" 2>/dev/null || true
    HTTPD_STARTED=1
  fi
}

# --- Linux: try system Apache + PHP ---
install_linux_apache_php() {
  if command -v php >/dev/null 2>&1 && php -r "exit(extension_loaded('curl') ? 0 : 1);" 2>/dev/null; then
    PHP_CMD="php"
    return 0
  fi
  log "Installing Apache and PHP (with curl)..."
  if command -v apt-get >/dev/null 2>&1; then
    sudo apt-get update -qq
    sudo apt-get install -y -qq apache2 php libapache2-mod-php php-curl php-json php-mbstring 2>/dev/null || true
    PHP_CMD="php"
    if command -v systemctl >/dev/null 2>&1; then
      sudo systemctl start apache2 2>/dev/null || true
      HTTPD_STARTED=1
    fi
    return 0
  fi
  if command -v dnf >/dev/null 2>&1; then
    sudo dnf install -y httpd php php-curl php-json php-mbstring 2>/dev/null || true
    PHP_CMD="php"
    sudo systemctl start httpd 2>/dev/null || true
    HTTPD_STARTED=1
    return 0
  fi
  if command -v yum >/dev/null 2>&1; then
    sudo yum install -y httpd php php-curl php-json php-mbstring 2>/dev/null || true
    PHP_CMD="php"
    sudo systemctl start httpd 2>/dev/null || true
    HTTPD_STARTED=1
    return 0
  fi
  return 1
}

# --- macOS: try Homebrew PHP or built-in PHP ---
install_macos_php() {
  if command -v php >/dev/null 2>&1; then
    PHP_CMD="php"
    return 0
  fi
  if command -v brew >/dev/null 2>&1; then
    log "Installing PHP via Homebrew..."
    brew install php 2>/dev/null || true
    PHP_CMD="php"
    return 0
  fi
  return 1
}

# --- Windows: only XAMPP or manual ---
install_windows_instructions() {
  err "XAMPP not found."
  echo ""
  echo "  Quick install on Windows:"
  echo "  1. Download XAMPP: https://www.apachefriends.org/download.html"
  echo "  2. Install to C:\\xampp (default)"
  echo "  3. Run this script again from Git Bash or place the app in C:\\xampp\\htdocs\\$APP_NAME"
  echo ""
  return 1
}

# --- ensure .env exists ---
setup_env() {
  local dir="${1:-.}"
  if [[ -f "$dir/.env" ]]; then
    log ".env already exists in $dir"
    return 0
  fi
  if [[ -f "$dir/.env.example" ]]; then
    cp "$dir/.env.example" "$dir/.env"
    log "Created $dir/.env from .env.example — edit it to add your API keys."
  else
    warn "No .env.example found; create .env with your API keys."
  fi
}

# --- open default system browser ---
open_browser() {
  local url="$1"
  local os
  os=$(detect_os)
  if [[ -z "$url" ]]; then return; fi
  log "Opening $url in default browser..."
  case "$os" in
    linux)   xdg-open "$url" 2>/dev/null || true ;;
    macos)   open "$url" 2>/dev/null || true ;;
    windows) start "$url" 2>/dev/null || cmd //c start "$url" 2>/dev/null || true ;;
    *)       true ;;
  esac
}

# --- after Apache is running: ensure app is in XAMPP htdocs, then open browser ---
clone_in_htdocs_and_open() {
  [[ -z "$XAMPP_FOUND" || -z "$HTDOCS" ]] && return 0
  if [[ ! -d "$HTDOCS" ]]; then
    warn "htdocs not found: $HTDOCS"
    return 0
  fi
  local target="$HTDOCS/$CLONE_DIR_NAME"
  local saved_pwd
  saved_pwd="$(pwd)"
  if [[ ! -d "$target" ]]; then
    log "Cloning into XAMPP htdocs: $target"
    if ! (cd "$HTDOCS" && git clone https://github.com/ZakRowton/LivingAIMemoryDashboard.git); then
      err "Could not clone. Install git and try again."
      cd "$saved_pwd" 2>/dev/null || true
      return 1
    fi
  else
    log "App already present at $target"
  fi
  setup_env "$target"
  cd "$saved_pwd" 2>/dev/null || true
  open_browser "http://localhost/$CLONE_DIR_NAME/"
}

# --- main ---
main() {
  local os
  os=$(detect_os)
  log "Detected OS: $os"

  # If we're inside the repo, use current dir as app dir
  if [[ -f "api/chat.php" && -f "index.php" ]]; then
    INSTALL_DIR="$(pwd)"
    log "Running from project root: $INSTALL_DIR"
    DOCROOT_APP_DIR="$INSTALL_DIR"
    setup_env "$INSTALL_DIR"
    # Detect if we're already under a known htdocs path for URL
    if [[ "$INSTALL_DIR" == *"/htdocs/"* || "$INSTALL_DIR" == *"\\htdocs\\"* || "$INSTALL_DIR" == *"/htdocs"* ]]; then
      APP_SUBPATH="${INSTALL_DIR##*htdocs}"
      APP_SUBPATH="${APP_SUBPATH#/}"
      APP_SUBPATH="${APP_SUBPATH#\\}"
      APP_SUBPATH="${APP_SUBPATH//\\/}"
      [[ -z "$APP_SUBPATH" ]] && APP_SUBPATH="$APP_NAME"
    fi
  else
    # Otherwise we'll clone or use a target dir
    INSTALL_DIR="${MEMORYGRAPH_INSTALL_DIR:-$(pwd)/$APP_NAME}"
    if [[ ! -d "$INSTALL_DIR" ]]; then
      log "Cloning repository into $INSTALL_DIR ..."
      git clone "$REPO_URL" "$INSTALL_DIR" 2>/dev/null || {
        err "Could not clone. Install git and try again, or clone manually and run this script from the project root."
        exit 1
      }
    fi
    if [[ ! -f "$INSTALL_DIR/api/chat.php" ]]; then
      err "Not a valid $APP_NAME project: $INSTALL_DIR"
      exit 1
    fi
    DOCROOT_APP_DIR="$INSTALL_DIR"
    setup_env "$INSTALL_DIR"
  fi

  case "$os" in
    linux)
      XAMPP_FOUND=$(find_xampp_linux) || true
      if [[ -n "$XAMPP_FOUND" ]]; then
        HTDOCS="$XAMPP_FOUND/htdocs"
        start_xampp_linux "$XAMPP_FOUND"
        if [[ -z "$HTTPD_STARTED" ]]; then
          install_linux_apache_php || true
        fi
        if [[ -n "$HTTPD_STARTED" ]]; then
          clone_in_htdocs_and_open
        fi
      else
        install_linux_apache_php || true
        if [[ -n "$PHP_CMD" && -z "$HTTPD_STARTED" ]]; then
          log "Starting built-in PHP server on port 8080..."
          log "Run this in the project directory to serve: php -S localhost:8080"
          log "Then open: http://localhost:8080/"
        fi
      fi
      ;;
    macos)
      XAMPP_FOUND=$(find_xampp_macos) || true
      if [[ -n "$XAMPP_FOUND" ]]; then
        HTDOCS="$XAMPP_FOUND/htdocs"
        start_xampp_macos "$XAMPP_FOUND"
        if [[ -n "$HTTPD_STARTED" ]]; then
          clone_in_htdocs_and_open
        fi
      fi
      if [[ -z "$HTTPD_STARTED" ]]; then
        install_macos_php || true
        if [[ -n "$PHP_CMD" ]]; then
          log "Starting built-in PHP server on port 8080..."
          (cd "$INSTALL_DIR" && php -S localhost:8080 &) 2>/dev/null || true
          sleep 1
          log "Then open: http://localhost:8080/"
        fi
      fi
      ;;
    windows)
      XAMPP_FOUND=$(find_xampp_windows) || true
      if [[ -n "$XAMPP_FOUND" ]]; then
        HTDOCS="${XAMPP_FOUND}/htdocs"
        start_xampp_windows "$XAMPP_FOUND"
        if [[ -n "$HTTPD_STARTED" && -d "$HTDOCS" ]]; then
          clone_in_htdocs_and_open
        fi
      else
        install_windows_instructions || exit 1
      fi
      ;;
    *)
      warn "Unknown OS. Install PHP 8+ with curl and run: php -S localhost:8080"
      exit 1
      ;;
  esac

  # Print final URL when possible
  if [[ -n "$APP_SUBPATH" ]]; then
    log "Open in browser: http://localhost/${APP_SUBPATH}/"
  elif [[ -n "$XAMPP_FOUND" && -n "$INSTALL_DIR" ]]; then
    if [[ "$INSTALL_DIR" == "$XAMPP_FOUND/htdocs"* ]]; then
      path="${INSTALL_DIR#$XAMPP_FOUND/htdocs}"
      path="${path#/}"
      path="${path//\\/}"
      [[ -n "$path" ]] && log "Open in browser: http://localhost/${path}/" || log "Open in browser: http://localhost/"
    fi
  fi
  log "Done. Edit .env in $INSTALL_DIR to add your API keys."
}

main "$@"
