#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
: "${DELIVERY_TEST_SOCKET:?Set DELIVERY_TEST_SOCKET to the isolated MySQL Unix socket}"
: "${DELIVERY_TEST_PASSWORD:?Set the isolated test password}"
[[ "$DELIVERY_TEST_SOCKET" == /tmp/nexdine-delivery-*/mysql.sock ]] || { echo 'Use an isolated /tmp delivery-test socket.' >&2; exit 1; }
export APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=nexdine_delivery_test
export DB_SOCKET="$DELIVERY_TEST_SOCKET" DB_USERNAME=delivery_test DB_PASSWORD="$DELIVERY_TEST_PASSWORD"
export CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array MAIL_MAILER=array
# PHPUnit RefreshDatabase migrates only this explicit test schema.
php artisan test --filter='DeliveryFinancialLifecycleTest|DeliveryMoneyTest|CustomerDeliveryQuoteTest|DeliveryFoundationTest|DeliverySettingValidationTest|UengageContractTest|DeliveryStateMachineSecurityTest'
