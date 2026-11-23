#!/usr/bin/env bash
# Disposable WordPress integration environment using official prebuilt images.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_TEST_PROJECT="${PIN_WP_TEST_PROJECT:-pin-fse-integration}"
compose=(docker compose --project-name "$WP_TEST_PROJECT" -f "$SCRIPT_DIR/wordpress-test.yml")
case "${1:-}" in
  start)
    "${compose[@]}" up -d db wordpress
    ready=0
    for attempt in $(seq 1 40); do
      if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then ready=1; break; fi
      sleep 2
    done
    if [ "$ready" != 1 ]; then "${compose[@]}" logs; exit 1; fi
    "${compose[@]}" run --rm cli core install --url=http://localhost:8888 --title='PIN isolated integration' --admin_user=admin --admin_password=password --admin_email=qa@example.invalid --skip-email
    ;;
  run)
    if [ "${2:-}" != cli ] || [ "${3:-}" != wp ]; then echo 'Usage: wordpress-test.sh run cli wp <arguments>' >&2; exit 2; fi
    shift 3
    "${compose[@]}" run --rm -T cli "$@"
    ;;
  stop)
    "${compose[@]}" down --volumes --remove-orphans
    ;;
  *) echo 'Usage: wordpress-test.sh start|run cli wp ...|stop' >&2; exit 2 ;;
esac
