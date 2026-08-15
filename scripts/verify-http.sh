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

# La coppia dell'autenticazione del dominio non sta in questo file: si legge da
# /home/webtest/.basic-44123 (chmod 600), e la variabile d'ambiente la scavalca.
# Un repository non e' il posto di una password, e la storia di git diventa
# pubblica tutta insieme, non solo l'ultimo commit.
BASE=${BASE:-https://test.44123.it/oxysuppliers}
BASIC=${BASIC:-$(cat "${BASIC_FILE:-/home/webtest/.basic-44123}" 2>/dev/null)}
SITE=${SITE:-/home/webtest/web/test.44123.it/public_html/oxysuppliers}
BENCH=${BENCH:-bench}

if [ -z "$BASIC" ]; then
	echo "non trovo la coppia del dominio: passa BASIC=utente:password" >&2
	exit 1
fi

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

# Si entra coi cookie che WordPress stesso emette, non dal modulo di accesso.
# Le password degli utenti del banco stavano in chiaro qui dentro, andavano
# passate a ogni lancio, e sono cambiate: quando succede tutte le verifiche si
# dichiarano rotte per il motivo sbagliato. Quello che si prova qui non e'
# l'accesso, che e' di WordPress e funziona, ma cosa vede e cosa non vede chi
# e' gia' entrato.
#
# Ne servono TRE: wp-admin su HTTPS passa da auth_redirect(), che pretende
# quello sicuro, e col solo logged_in rimanda al modulo di accesso.
sign_in() {
	local who="$1" user="$2"
	local righe nome valore

	righe=$(sudo -u webtest -H bash -c "cd $SITE && wp eval-file $BENCH/bench-auth-cookie.php $user" | sed 's/[[:space:]]*$//')

	: > "$JARS/$who"

	while IFS=$'\t' read -r nome valore; do
		[ -n "$valore" ] || continue

		printf '%s\tFALSE\t%s\tTRUE\t0\t%s\t%s\n' \
			'test.44123.it' '/' "$nome" "$valore" >> "$JARS/$who"
	done <<< "$righe"

	if grep -q wordpress_logged_in "$JARS/$who"; then
		pass "accesso di $who"
	else
		fail "accesso di $who ($righe)"
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
sign_in admin oxyadmin
sign_in manager magazzino
sign_in editor redattore

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
# Two states, and the empty one is the one people meet first: a shop that has
# just installed the plugin has no suppliers, and the panel has to say what to
# do about it rather than show an empty table.
PRODUCT=$(get admin "$BASE/wp-admin/post.php?post=$PRODUCT_ID&action=edit")
check "la scheda si apre" "$PRODUCT" "woocommerce_options_panel"
check "c'e' la linguetta Fornitori" "$PRODUCT" "oxysuppliers_product_data"
check "e il suo nonce" "$PRODUCT" "oxysuppliers_product_nonce"
check "senza fornitori invita ad aggiungerne uno" "$PRODUCT" "Add the first one"

FORM=$(get admin "$SUPPLIERS&action=new")
NONCE=$(nonce_from "$FORM")
post admin "$ADMIN_POST" "action=oxysuppliers_save_supplier&_wpnonce=$NONCE&id=0&company_name=Fornitore+Del+Pannello&currency=EUR&lead_time_days=4" > /dev/null

PRODUCT=$(get admin "$BASE/wp-admin/post.php?post=$PRODUCT_ID&action=edit")
check "con un fornitore compare la tabella" "$PRODUCT" "oxysuppliers-lines"
check "e il fornitore e' fra le scelte" "$PRODUCT" "Fornitore Del Pannello"
check "con la riga vuota per aggiungerne uno" "$PRODUCT" "oxysuppliers_lines[rows][new]"

PRODUCT_AS_EDITOR=$(get editor "$BASE/wp-admin/post.php?post=$PRODUCT_ID&action=edit")
check_absent "un editor non vede il pannello" "$PRODUCT_AS_EDITOR" "oxysuppliers_product_data"

echo
echo "== la schermata dei fabbisogni =="
REQUIREMENTS="$BASE/wp-admin/admin.php?page=oxysuppliers&tab=requirements"
NEEDS=$(get admin "$REQUIREMENTS")
check "si apre" "$NEEDS" "What to reorder"
check "ed e' la prima linguetta" "$NEEDS" "tab=requirements"
check "con le colonne che servono" "$NEEDS" "Reorder at / up to"
check "e i venduti" "$NEEDS" "Sold 7 / 30 / 90"
check "mostra un articolo vero del negozio" "$NEEDS" "MOUSE-X"
check "col bottone per esportare" "$NEEDS" "Export what is shown"

FILTERED=$(get admin "$REQUIREMENTS&no_supplier=1")
check "il filtro senza fornitore risponde" "$FILTERED" "Only without a supplier"

NOTHING=$(get admin "$REQUIREMENTS&s=zzzznessunarticolo")
check "e una ricerca a vuoto lo dice" "$NOTHING" "Nothing matches those filters"

NEEDS_AS_EDITOR=$(status_of editor "$REQUIREMENTS")
if [ "$NEEDS_AS_EDITOR" = "403" ] || ! printf '%s' "$(get editor "$REQUIREMENTS")" | grep -qF "What to reorder"; then
	pass "un editor non la vede"
else
	fail "un editor non la vede"
fi

echo
echo "== l'esportazione =="
EXPORT=$(printf '%s' "$NEEDS" | grep -o 'admin-post.php?action=oxysuppliers_export_requirements[^"]*' | head -1 | sed 's/&#038;/\&/g')
CSV=$(curl -sS -u "$BASIC" -b "$JARS/admin" -L "$BASE/wp-admin/$EXPORT")
check "il CSV ha le intestazioni" "$CSV" "SKU"
check "e le colonne dell'ordine" "$CSV" "To order"
check "e i dati del negozio" "$CSV" "MOUSE-X"

echo
echo "== gli ordini fornitore =="
ORDERS="$BASE/wp-admin/admin.php?page=oxysuppliers&tab=orders"
LIST=$(get admin "$ORDERS")
check "la linguetta si apre" "$LIST" "Purchase orders"
check "e dice che non ce n'e' ancora nessuno" "$LIST" "No purchase order yet"

NEW=$(get admin "$ORDERS&action=new")
check "il modulo per iniziarne uno" "$NEW" "Start the order"
check "con il fornitore da scegliere" "$NEW" "Fornitore Del Pannello"

NONCE=$(nonce_from "$NEW")
SUPPLIER_ID=$(printf '%s' "$NEW" | grep -o '<option value="[0-9]*">Fornitore Del Pannello' | grep -o '[0-9]*' | head -1)
CREATED=$(post admin "$ADMIN_POST" "action=oxysuppliers_create_order&_wpnonce=$NONCE&supplier_id=$SUPPLIER_ID&order_date=2026-08-13&expected_date=")
check "l'ordine viene creato" "$CREATED" "Purchase order started"
check "con un numero suo" "$CREATED" "PO-$(date +%Y)-"
check "ed e' una bozza" "$CREATED" "Draft"

echo
echo "== solo le mosse che la macchina a stati permette =="
check "una bozza si puo' annullare" "$CREATED" "Cancel"
check_absent "ma non si puo' dichiarare ricevuta" "$CREATED" "All received"

# An empty order has nothing to tell a supplier, so the button is not drawn.
check_absent "e nemmeno inviare, perche' e' vuota" "$CREATED" "Mark as sent"

ORDER_ID=$(printf '%s' "$CREATED" | grep -o 'action=view&#038;id=[0-9]*' | head -1 | grep -o '[0-9]*$')

if [ -z "$ORDER_ID" ]; then
	ORDER_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query 'SELECT id FROM oxs_oxysuppliers_purchase_orders ORDER BY id DESC LIMIT 1' --skip-column-names")
fi

CANCEL=$(printf '%s' "$CREATED" | grep -o "admin-post.php?action=oxysuppliers_order_status&#038;id=$ORDER_ID&#038;status=cancelled&#038;_wpnonce=[a-z0-9]*" | head -1 | sed 's/&#038;/\&/g')
CANCELLED=$(get admin "$BASE/wp-admin/$CANCEL")
check "annullandolo lo dice" "$CANCELLED" "Cancelled"
check_absent "e non offre piu' nessuna mossa" "$CANCELLED" "Supplier confirmed"

echo
echo "== e senza nonce non si muove niente =="
NO_NONCE=$(status_of admin "$ADMIN_POST?action=oxysuppliers_order_status&id=$ORDER_ID&status=sent")
if [ "$NO_NONCE" = "403" ]; then
	pass "un cambio di stato senza nonce viene rifiutato (403)"
else
	fail "un cambio di stato senza nonce viene rifiutato (ho avuto $NO_NONCE)"
fi

ORDERS_AS_EDITOR=$(get editor "$ORDERS")
check_absent "un editor non vede gli ordini" "$ORDERS_AS_EDITOR" "New purchase order"

echo
echo "== un ordine vuoto non ha un documento da stampare =="
check_absent "niente PDF su un ordine senza righe" "$CREATED" "Download the PDF"

# One line, put there as a fixture: the point of what follows is the document
# and who may have it, not how the line got on the order.
mysql_run "INSERT INTO oxs_oxysuppliers_purchase_order_items (po_id, product_id, sku, supplier_sku, description, qty_ordered, qty_received, unit_cost_minor, line_total_minor) VALUES ($ORDER_ID, 10, 'MOUSE-X', 'F-MX', 'Mouse X wireless', 20, 0, 1180, 23600)"

WITH_LINE=$(get admin "$ORDERS&action=view&id=$ORDER_ID")
check "con una riga il documento c'e'" "$WITH_LINE" "Download the PDF"

echo
echo "== e prima di spedire si vede a chi =="
check "il modulo d'invio mostra il destinatario" "$WITH_LINE" 'name="to"'
check "con oggetto e messaggio gia' scritti" "$WITH_LINE" 'name="subject"'
check "e dice che il PDF va allegato" "$WITH_LINE" "goes with it as an attachment"

echo
echo "== il PDF e' dietro un permesso, non dietro un indirizzo =="
PDF_LINK=$(printf '%s' "$WITH_LINE" | grep -o "admin-post.php?action=oxysuppliers_order_pdf&#038;id=$ORDER_ID&#038;_wpnonce=[a-z0-9]*" | head -1 | sed 's/&#038;/\&/g')

if [ -n "$PDF_LINK" ]; then
	PDF_TYPE=$(curl -sS -u "$BASIC" -b "$JARS/admin" -L -o /tmp/oxs-order.pdf -w '%{content_type}' "$BASE/wp-admin/$PDF_LINK")

	if [ "$PDF_TYPE" = "application/pdf" ] && head -c 5 /tmp/oxs-order.pdf | grep -q '%PDF'; then
		pass "un amministratore lo scarica ed e' un PDF"
	else
		fail "un amministratore lo scarica ed e' un PDF (tipo: $PDF_TYPE)"
	fi

	# The same address, with its nonce, in somebody else's hands.
	EDITOR_TYPE=$(curl -sS -u "$BASIC" -b "$JARS/editor" -o /tmp/oxs-editor.out -w '%{http_code}' "$BASE/wp-admin/$PDF_LINK")

	if [ "$EDITOR_TYPE" = "403" ] || ! head -c 5 /tmp/oxs-editor.out | grep -q '%PDF'; then
		pass "un editor con lo stesso indirizzo non lo ottiene"
	else
		fail "un editor con lo stesso indirizzo non lo ottiene (ha avuto $EDITOR_TYPE)"
	fi

	NO_NONCE_PDF=$(status_of admin "$ADMIN_POST?action=oxysuppliers_order_pdf&id=$ORDER_ID")

	if [ "$NO_NONCE_PDF" = "403" ]; then
		pass "e senza nonce nemmeno l'amministratore (403)"
	else
		fail "e senza nonce nemmeno l'amministratore (ho avuto $NO_NONCE_PDF)"
	fi

	rm -f /tmp/oxs-order.pdf /tmp/oxs-editor.out
else
	fail "il collegamento al PDF e' sulla pagina dell'ordine"
fi

echo
echo "== ricezione merce: lo stesso modulo inviato due volte =="

# A fresh order, already sent, for a product the seeded shop really has.
mysql_run "INSERT INTO oxs_oxysuppliers_purchase_orders (po_number, supplier_id, status, currency, order_date, created_at, updated_at) VALUES ('PO-RIC-1', $SUPPLIER_ID, 'sent', 'EUR', CURDATE(), NOW(), NOW())"
RECV_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT id FROM oxs_oxysuppliers_purchase_orders WHERE po_number = 'PO-RIC-1'\" --skip-column-names")
MOUSE_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT ID FROM oxs_posts WHERE post_name = 'mouse-x-wireless' OR post_title = 'Mouse X wireless' LIMIT 1\" --skip-column-names")
mysql_run "INSERT INTO oxs_oxysuppliers_purchase_order_items (po_id, product_id, sku, supplier_sku, description, qty_ordered, qty_received, unit_cost_minor, line_total_minor) VALUES ($RECV_ID, $MOUSE_ID, 'MOUSE-X', 'F-MX', 'Mouse X wireless', 30, 0, 1180, 35400)"
LINE_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT id FROM oxs_oxysuppliers_purchase_order_items WHERE po_id = $RECV_ID\" --skip-column-names")

stock_now() {
	sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp eval 'wc_delete_product_transients( $MOUSE_ID ); echo (int) wc_get_product( $MOUSE_ID )->get_stock_quantity();'"
}

ORDER_PAGE=$(get admin "$ORDERS&action=view&id=$RECV_ID")
check "il modulo di ricezione c'e'" "$ORDER_PAGE" "Receive what has arrived"
check "con la sua chiave di idempotenza" "$ORDER_PAGE" 'name="idempotency_key"'

# Exactly what a browser would send — and then exactly the same thing again,
# which is what a double click, a reload or a retried request produces.
# The page carries three forms, so the nonce has to come from inside the right
# one: everything after the receiving form's action field, first nonce found.
RECV_NONCE=$(printf '%s' "$ORDER_PAGE" | sed -n '/value="oxysuppliers_receive_order"/,$p' | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
RECV_KEY=$(printf '%s' "$ORDER_PAGE" | grep -o 'name="idempotency_key" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
BODY="action=oxysuppliers_receive_order&_wpnonce=$RECV_NONCE&id=$RECV_ID&idempotency_key=$RECV_KEY&received%5B$LINE_ID%5D=10&reference=DDT-HTTP"

STOCK_BEFORE=$(stock_now)
FIRST=$(post admin "$ADMIN_POST" "$BODY")
STOCK_AFTER_ONE=$(stock_now)

check "la prima volta registra la consegna" "$FIRST" "Delivery recorded"

if [ "$STOCK_AFTER_ONE" = "$((STOCK_BEFORE + 10))" ]; then
	pass "e la giacenza sale di dieci ($STOCK_BEFORE -> $STOCK_AFTER_ONE)"
else
	fail "e la giacenza sale di dieci ($STOCK_BEFORE -> $STOCK_AFTER_ONE)"
fi

SECOND=$(post admin "$ADMIN_POST" "$BODY")
STOCK_AFTER_TWO=$(stock_now)

check "la seconda volta lo dice invece di rifarlo" "$SECOND" "had already been recorded"

if [ "$STOCK_AFTER_TWO" = "$STOCK_AFTER_ONE" ]; then
	pass "E LA GIACENZA NON SI MUOVE ($STOCK_AFTER_TWO)"
else
	fail "E LA GIACENZA NON SI MUOVE ($STOCK_AFTER_ONE -> $STOCK_AFTER_TWO)"
fi

RECEIPTS=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT COUNT(*) FROM oxs_oxysuppliers_receipts WHERE po_id = $RECV_ID\" --skip-column-names")

if [ "$RECEIPTS" = "1" ]; then
	pass "e resta una sola ricezione"
else
	fail "e resta una sola ricezione (ne ho trovate $RECEIPTS)"
fi

echo
echo "== e chi non ha il permesso non riceve niente =="
NO_NONCE_RECV=$(status_of admin "$ADMIN_POST?action=oxysuppliers_receive_order&id=$RECV_ID")

if [ "$NO_NONCE_RECV" = "403" ]; then
	pass "senza nonce viene rifiutato (403)"
else
	fail "senza nonce viene rifiutato (ho avuto $NO_NONCE_RECV)"
fi

RECV_AS_EDITOR=$(get editor "$ORDERS&action=view&id=$RECV_ID")
check_absent "un editor non vede il modulo di ricezione" "$RECV_AS_EDITOR" "Receive what has arrived"

echo
echo "== i report =="
REPORTS="$BASE/wp-admin/admin.php?page=oxysuppliers&tab=reports"
CARDS=$(get admin "$REPORTS")
check "la linguetta si apre" "$CARDS" "Reports"
check "quanto e' impegnato" "$CARDS" "Money committed"
check "quanti ordini aperti" "$CARDS" "Open orders"
check "quanti articoli sotto scorta" "$CARDS" "Below the reorder point"
check "e quanti in ritardo" "$CARDS" "Late"
check "dice anche cosa NON fa il gratuito" "$CARDS" "are in the paid add-on"

REPORTS_AS_EDITOR=$(get editor "$REPORTS")
check_absent "un editor non li vede" "$REPORTS_AS_EDITOR" "Money committed"

echo
echo "== la merce in arrivo sulla scheda prodotto =="
# Questo script parte da tabelle vuote, quindi l'ordine aperto se lo crea:
# dipendere di quello lasciato da un altro script vorrebbe dire dipendere
# dall'ordine in cui si lanciano.
OPEN_ORDER=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp eval-file wp-content/plugins/oxysuppliers-for-woocommerce/scripts/bench-open-order.php")
ETA=${OPEN_ORDER% *}
DUE=${OPEN_ORDER#* }
MOUSE_ID=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT post_id FROM oxs_postmeta WHERE meta_key = '_sku' AND meta_value = 'MOUSE-X' LIMIT 1\" --skip-column-names")
MOUSE_PAGE=$(get admin "$BASE/wp-admin/post.php?post=$MOUSE_ID&action=edit")
check "dice quanta merce sta arrivando" "$MOUSE_PAGE" "On the way:"
check "quanta ne sta arrivando davvero" "$MOUSE_PAGE" "On the way:</strong> $DUE"
check "e quando dovrebbe arrivare" "$MOUSE_PAGE" "$ETA"

echo
echo "== l'import di un listino =="
BEFORE_LINES=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT COUNT(*) FROM oxs_oxysuppliers_supplier_products\" --skip-column-names")

IMPORT="$BASE/wp-admin/admin.php?page=oxysuppliers&tab=import"
UPLOAD_FORM=$(get admin "$IMPORT")
check "la linguetta si apre" "$UPLOAD_FORM" "Import a price list"
check "e promette di non toccare niente prima" "$UPLOAD_FORM" "Nothing is changed until you have seen what it would do"

UPLOAD_NONCE=$(nonce_from "$UPLOAD_FORM")
CSV_FILE=$(mktemp /tmp/listino-XXXX.csv)

# Il file come lo salva un foglio di calcolo italiano: BOM, punto e virgola,
# virgola decimale, intestazioni in italiano.
printf '\xEF\xBB\xBFragione sociale;codice interno;codice fornitore;prezzo;minimo;multiplo;giorni\nFornitore Importato;MOUSE-X;IMP-MX;9,90;10;5;6\n' > "$CSV_FILE"

PREVIEW=$(curl -sS -u "$BASIC" -b "$JARS/admin" -c "$JARS/admin" -L \
	-F "action=oxysuppliers_import_upload" \
	-F "_wpnonce=$UPLOAD_NONCE" \
	-F "oxysuppliers_csv=@$CSV_FILE;type=text/csv" \
	"$ADMIN_POST")

check "il file viene letto" "$PREVIEW" "What this would do"
check "e dice cosa succederebbe" "$PREVIEW" "new supplier"
check "col bottone per confermare" "$PREVIEW" "Do it"

MIDDLE_LINES=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT COUNT(*) FROM oxs_oxysuppliers_supplier_products\" --skip-column-names")

if [ "$MIDDLE_LINES" = "$BEFORE_LINES" ]; then
	pass "L ANTEPRIMA NON HA SCRITTO NIENTE ($BEFORE_LINES righe)"
else
	fail "L ANTEPRIMA NON HA SCRITTO NIENTE ($BEFORE_LINES -> $MIDDLE_LINES)"
fi

CONFIRM_NONCE=$(nonce_from "$PREVIEW")
APPLIED=$(post admin "$ADMIN_POST" "action=oxysuppliers_import_confirm&_wpnonce=$CONFIRM_NONCE")

AFTER_LINES=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT COUNT(*) FROM oxs_oxysuppliers_supplier_products\" --skip-column-names")

if [ "$AFTER_LINES" -gt "$BEFORE_LINES" ]; then
	pass "e confermando invece scrive ($BEFORE_LINES -> $AFTER_LINES)"
else
	fail "e confermando invece scrive ($BEFORE_LINES -> $AFTER_LINES)"
fi

IMPORTED_COST=$(sudo -u webtest -H bash -c "cd /home/webtest/web/test.44123.it/public_html/oxysuppliers && wp db query \"SELECT unit_cost_minor FROM oxs_oxysuppliers_supplier_products WHERE supplier_sku = 'IMP-MX' LIMIT 1\" --skip-column-names")

if [ "$IMPORTED_COST" = "990" ]; then
	pass "e 9,90 e' arrivato come 990 centesimi"
else
	fail "e 9,90 e' arrivato come 990 centesimi (ho letto $IMPORTED_COST)"
fi

IMPORT_AS_EDITOR=$(get editor "$IMPORT")
check_absent "un editor non importa niente" "$IMPORT_AS_EDITOR" "Import a price list"

NO_NONCE_UPLOAD=$(status_of admin "$ADMIN_POST?action=oxysuppliers_import_upload")

if [ "$NO_NONCE_UPLOAD" = "403" ]; then
	pass "senza nonce il caricamento viene rifiutato (403)"
else
	fail "senza nonce il caricamento viene rifiutato (ho avuto $NO_NONCE_UPLOAD)"
fi

rm -f "$CSV_FILE"

echo
echo "== le rotte REST viste da fuori =="
# Da qui si prova soltanto che la porta e' chiusa. Con il solo cookie
# WordPress non riconosce nessuno sulle rotte REST -- vuole anche l intestazione
# X-WP-Nonce -- quindi anche l amministratore risulterebbe uno sconosciuto, e un
# 401 non direbbe niente sui permessi. Quelli, ruolo per ruolo, li prova
# verify-sprint7.php da dentro WordPress.
# Il banco ha i permalink semplici, quindi /wp-json/ non esiste: la forma che
# funziona ovunque e' ?rest_route=, ed e' quella che va provata.
REST="$BASE/?rest_route=/oxysuppliers/v1"

for route in requirements orders suppliers; do
	ANON=$(curl -sS -u "$BASIC" -o /dev/null -w '%{http_code}' "$REST/$route")

	if [ "$ANON" = "401" ] || [ "$ANON" = "403" ]; then
		pass "/$route e' chiusa a chi non ha fatto l accesso ($ANON)"
	else
		fail "/$route e' chiusa a chi non ha fatto l accesso (ho avuto $ANON)"
	fi
done

WRITE=$(curl -sS -u "$BASIC" -o /dev/null -w '%{http_code}' -X POST "$REST/suppliers")

if [ "$WRITE" = "404" ] || [ "$WRITE" = "405" ] || [ "$WRITE" = "401" ]; then
	pass "e in scrittura non esiste proprio ($WRITE)"
else
	fail "e in scrittura non esiste proprio (ho avuto $WRITE)"
fi

echo
echo "== ==============================="
echo "== superati: $PASSED   falliti: $FAILED"
rm -rf "$JARS"
[ "$FAILED" -eq 0 ]
