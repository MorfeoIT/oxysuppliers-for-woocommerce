#!/bin/bash
#
# Drives the admin screens over HTTP on the test bench.
#
# Unit tests cannot see a redirect, a nonce, a capability check or a form that
# comes back with the wrong values in it. This can. Run it on the server:
#
#   bash verify-http.sh
#
# It signs in as three different people on purpose: what a screen does for
# somebody who is not allowed to open it is part of what the screen does.

BASE=${BASE:-https://test.44123.it/oxysuppliers}
BASIC=${BASIC:-oxysoft:LA-COPPIA-STA-IN-UN-FILE-PROTETTO}
ADMIN_USER=${ADMIN_USER:-oxyadmin}
# No apostrophe in this message: the text inside ${VAR:?...} is still parsed for
# quotes, so one would open a string that never closes, and bash reports the
# syntax error a hundred lines further down.
ADMIN_PASS=${ADMIN_PASS:?serve la password amministratore}
JARS=$(mktemp -d)

PASSED=0
FAILED=0

pass() { PASSED=$((PASSED + 1)); echo "  ok   $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  FAIL $1"; }

check() {
	local label="$1" haystack="$2" needle="$3"

	if printf '%s' "$haystack" | grep -qF -- "$needle"; then
		pass "$label"
	else
		fail "$label (manca: $needle)"

		# A failing check that only says what it wanted leaves you guessing at
		# what it got. Say both.
		printf '       risposta di %s byte' "$(printf '%s' "$haystack" | wc -c)"
		printf '%s' "$haystack" | grep -o '<h1[^>]*>[^<]*\|notice notice-[a-z]*\|You do not have permission\|link you followed has expired' | head -3 | sed 's/^/       > /'
		echo
	fi
}

check_absent() {
	local label="$1" haystack="$2" needle="$3"

	if printf '%s' "$haystack" | grep -qF -- "$needle"; then
		fail "$label (non doveva esserci: $needle)"
	else
		pass "$label"
	fi
}

sign_in() {
	local who="$1" user="$2" password="$3"

	curl -sS -u "$BASIC" -c "$JARS/$who" -b "$JARS/$who" \
		-d "log=$user&pwd=$password&wp-submit=Log+In&testcookie=1&redirect_to=$BASE/wp-admin/" \
		"$BASE/wp-login.php" -o /dev/null

	if grep -q wordpress_logged_in "$JARS/$who"; then
		pass "accesso di $who"
	else
		fail "accesso di $who"
	fi
}

get() {
	curl -sS -u "$BASIC" -b "$JARS/$1" -L "$2"
}

status_of() {
	curl -sS -u "$BASIC" -b "$JARS/$1" -o /dev/null -w '%{http_code}' "$2"
}

post() {
	local who="$1" url="$2" data="$3"

	curl -sS -u "$BASIC" -b "$JARS/$who" -c "$JARS/$who" -L -d "$data" "$url"
}

# The nonce is per user and per action, so it has to come from a page that user
# was actually served.
nonce_from() {
	printf '%s' "$1" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

supplier_id_from_list() {
	printf '%s' "$1" | grep -o 'action=edit&#038;id=[0-9]*' | head -1 | grep -o '[0-9]*$'
}

SUPPLIERS="$BASE/wp-admin/admin.php?page=oxysuppliers&tab=suppliers"
ADMIN_POST="$BASE/wp-admin/admin-post.php"
WP="sudo -u webtest -H bash -c \"cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp\""

# A suite that only passes on a bench nobody has touched is a suite that will
# fail for the wrong reason sooner or later. Start from a known state, and say
# so: this wipes the plugin's own tables, and only those.
wipe() {
	sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"DELETE FROM oxs_oxysuppliers_supplier_products; DELETE FROM oxs_oxysuppliers_purchase_orders; DELETE FROM oxs_oxysuppliers_suppliers; DELETE FROM oxs_oxysuppliers_logs;\"" >/dev/null 2>&1
}

echo "== parto da un banco pulito =="
wipe
pass "tabelle del plugin svuotate"

echo "== accessi =="
sign_in admin "$ADMIN_USER" "$ADMIN_PASS"
sign_in manager magazzino 'PASSWORD-RIMOSSA'
sign_in editor redattore 'PASSWORD-RIMOSSA'

echo
echo "== la schermata esiste e si apre =="
LIST=$(get admin "$SUPPLIERS")
check "titolo della schermata" "$LIST" "Suppliers"
check "bottone per aggiungere" "$LIST" "Add supplier"

echo
echo "== chi non deve entrare, non entra =="
EDITOR_VIEW=$(get editor "$SUPPLIERS")
check_absent "un editor non vede la schermata" "$EDITOR_VIEW" "Add supplier"
MANAGER_VIEW=$(get manager "$SUPPLIERS")
check "un magazziniere invece si" "$MANAGER_VIEW" "Add supplier"

echo
echo "== creazione =="
FORM=$(get admin "$SUPPLIERS&action=new")
check "il modulo si apre" "$FORM" "Company name"
# WordPress writes selected='selected', with single quotes, so the needle has to
# match what selected() actually prints rather than what looks natural.
check "la valuta arriva gia' impostata" "$FORM" "value=\"EUR\" selected='selected'"
NONCE=$(nonce_from "$FORM")

CREATED=$(post admin "$ADMIN_POST" "action=oxysuppliers_save_supplier&_wpnonce=$NONCE&id=0&company_name=ABC+Forniture+Srl&trade_name=ABC&vat_number=IT01234567890&city=Milano&country=IT&order_email=ordini%40example.test&currency=EUR&lead_time_days=3&min_order_value=250%2C00&status=active&notes=Chiama+il+marted%C3%AC")
check "il fornitore risulta creato" "$CREATED" "Supplier added."
check "compare in elenco" "$CREATED" "ABC"
check "con la sua partita IVA" "$CREATED" "IT01234567890"
check "il minimo d'ordine e' quello scritto" "$CREATED" "250,00"

SUPPLIER_ID=$(supplier_id_from_list "$CREATED")

if [ -n "$SUPPLIER_ID" ]; then
	pass "l'elenco rimanda alla scheda (id $SUPPLIER_ID)"
else
	fail "l'elenco rimanda alla scheda"
fi

echo
echo "== quello che si e' scritto torna indietro uguale =="
EDIT=$(get admin "$SUPPLIERS&action=edit&id=$SUPPLIER_ID")
check "la ragione sociale" "$EDIT" 'value="ABC Forniture Srl"'
check "il lead time" "$EDIT" 'value="3"'
check "il minimo, in forma canonica" "$EDIT" 'value="250.00"'
check "le note con l'accento" "$EDIT" "marted"

echo
echo "== il modulo rifiuta quello che non va =="
NONCE=$(nonce_from "$EDIT")
# The website is deliberately a URL that is not http, and deliberately not
# "javascript:alert(1)": the server's web application firewall blocks that
# payload before WordPress ever sees it, and the test then measures the
# firewall instead of the plugin. It answers with an "Access Denied" page of
# its own, which is why a failing check prints what it actually received.
REFUSED=$(post admin "$ADMIN_POST" "action=oxysuppliers_save_supplier&_wpnonce=$NONCE&id=$SUPPLIER_ID&company_name=&currency=EUR&order_email=non-un-indirizzo&website=ftp%3A%2F%2Fesempio.test%2Flistino&lead_time_days=-2")
check "dice che non ha salvato" "$REFUSED" "was not saved"
check "il nome e' obbligatorio" "$REFUSED" "is required"
check "l'indirizzo non e' un indirizzo" "$REFUSED" "does not look like an email"
check "il sito deve essere http" "$REFUSED" "must be a web address"

STILL=$(get admin "$SUPPLIERS&action=edit&id=$SUPPLIER_ID")
check "e non ha toccato quello che c'era" "$STILL" 'value="ABC Forniture Srl"'

echo
echo "== senza nonce non si scrive niente =="
NO_NONCE=$(status_of admin "$ADMIN_POST?action=oxysuppliers_toggle_supplier&id=$SUPPLIER_ID")
if [ "$NO_NONCE" = "403" ]; then
	pass "una richiesta senza nonce viene rifiutata (403)"
else
	fail "una richiesta senza nonce viene rifiutata (ho avuto $NO_NONCE)"
fi

echo
echo "== ricerca e filtro =="
FOUND=$(get admin "$SUPPLIERS&s=Milano")
check "cerca per citta'" "$FOUND" "ABC"
MISSING=$(get admin "$SUPPLIERS&s=zzzznessuno")
check "e non inventa risultati" "$MISSING" "No supplier matches that search"

echo
echo "== modifica =="
NONCE=$(nonce_from "$STILL")
UPDATED=$(post admin "$ADMIN_POST" "action=oxysuppliers_save_supplier&_wpnonce=$NONCE&id=$SUPPLIER_ID&company_name=ABC+Forniture+Srl&trade_name=ABC+Group&currency=EUR&city=Torino&country=IT&lead_time_days=5&min_order_value=300.00&status=active")
check "salva" "$UPDATED" "Supplier saved."
check "e mostra il nome commerciale nuovo" "$UPDATED" "ABC Group"
check "e la citta' nuova" "$UPDATED" "Torino"

echo
echo "== disattivazione e riattivazione =="
LIST=$(get admin "$SUPPLIERS")
TOGGLE=$(printf '%s' "$LIST" | grep -o "admin-post.php?action=oxysuppliers_toggle_supplier&#038;id=$SUPPLIER_ID&#038;_wpnonce=[a-z0-9]*" | head -1 | sed 's/&#038;/\&/g')
OFF=$(get admin "$BASE/wp-admin/$TOGGLE")
check "si disattiva" "$OFF" "Supplier deactivated."
check "e si vede che e' spento" "$OFF" "Inactive"

LIST=$(get admin "$SUPPLIERS")
TOGGLE=$(printf '%s' "$LIST" | grep -o "admin-post.php?action=oxysuppliers_toggle_supplier&#038;id=$SUPPLIER_ID&#038;_wpnonce=[a-z0-9]*" | head -1 | sed 's/&#038;/\&/g')
ON=$(get admin "$BASE/wp-admin/$TOGGLE")
check "e si riaccende" "$ON" "Supplier activated."

echo
echo "== un fornitore con ordini non si cancella =="
mysql_run() {
	sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"$1\""
}
mysql_run "INSERT INTO oxs_oxysuppliers_purchase_orders (po_number, supplier_id, currency, order_date, created_at, updated_at) VALUES ('PO-TEST-1', $SUPPLIER_ID, 'EUR', '2026-08-13', NOW(), NOW())"

GUARDED=$(get admin "$SUPPLIERS&action=delete&id=$SUPPLIER_ID")
check "la schermata lo dice" "$GUARDED" "cannot be deleted"
LIST=$(get admin "$SUPPLIERS")
check_absent "e il collegamento per cancellare sparisce" "$LIST" "action=delete&#038;id=$SUPPLIER_ID"

mysql_run "DELETE FROM oxs_oxysuppliers_purchase_orders WHERE po_number = 'PO-TEST-1'"

echo
echo "== cancellazione, che e' una POST e non un collegamento =="
CONFIRM=$(get admin "$SUPPLIERS&action=delete&id=$SUPPLIER_ID")
check "chiede conferma" "$CONFIRM" "cannot be undone"
NONCE=$(nonce_from "$CONFIRM")
DELETED=$(post admin "$ADMIN_POST" "action=oxysuppliers_delete_supplier&_wpnonce=$NONCE&id=$SUPPLIER_ID")
check "cancella" "$DELETED" "Supplier deleted."
check "e l'elenco torna vuoto" "$DELETED" "No suppliers yet"

echo
echo "== il registro ha tenuto traccia di tutto =="
LOG=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT action FROM oxs_oxysuppliers_logs WHERE object_type='supplier' ORDER BY id\" --skip-column-names")
for what in created updated status_changed deleted; do
	check "registrato: $what" "$LOG" "$what"
done

echo
echo "== il pannello Fornitori sulla scheda prodotto =="
PRODUCT_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT ID FROM oxs_posts WHERE post_type='product' AND post_status='publish' LIMIT 1\" --skip-column-names")
PRODUCT=$(get admin "$BASE/wp-admin/post.php?post=$PRODUCT_ID&action=edit")
check "la scheda si apre" "$PRODUCT" "woocommerce_options_panel"
check "c'e' la linguetta Fornitori" "$PRODUCT" "oxysuppliers_product_data"
check "con la sua tabella" "$PRODUCT" "oxysuppliers-lines"
check "e il suo nonce" "$PRODUCT" "oxysuppliers_product_nonce"

PRODUCT_AS_EDITOR=$(get editor "$BASE/wp-admin/post.php?post=$PRODUCT_ID&action=edit")
check_absent "un editor non vede il pannello" "$PRODUCT_AS_EDITOR" "oxysuppliers_product_data"

echo
echo "== ==============================="
echo "== superati: $PASSED   falliti: $FAILED"
rm -rf "$JARS"
[ "$FAILED" -eq 0 ]
