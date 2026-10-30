# Sourced by tests/e2e.sh. A rule that redefines the shared .wpmcp-code block once broke the copy boxes (light text on a light background).
N=$(grep -c '^\.wpmcp-code {' "$PLUGIN_DIR/assets/admin.css")
[ "$N" = "1" ] && echo "PASS css guard: .wpmcp-code is defined once" || { echo "FAIL css guard: .wpmcp-code defined $N times"; STATUS=1; }
