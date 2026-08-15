<?php
// I cookie di sessione di un utente del banco, senza password.
//
//   wp eval-file bench-auth-cookie.php oxyadmin
//
// Stampa una riga per cookie, «nome<TAB>valore». Le prove HTTP le mettono nel
// barattolo di curl e da li' in poi navigano come quell'utente.
//
// SONO TRE, e servono tutti e tre. Il cookie «logged_in» dice CHI sei, e basta
// da solo per il sito pubblico; wp-admin invece passa da auth_redirect(), che
// su HTTPS pretende quello sicuro — «wordpress_sec_». Con il solo logged_in la
// bacheca risponde rimandando al modulo di accesso, che dall'esterno sembra un
// cookie rifiutato e invece e' un cookie mancante.
//
// PERCHE' COSI'. Prima queste prove facevano il giro dal modulo di accesso con
// la password dell'amministratore, scritta in chiaro in un file sul server e da
// passare a ogni lancio. Il giorno che cambia — ed e' cambiata — tutte le
// verifiche si dichiarano rotte per il motivo sbagliato. Questi cookie li emette
// WordPress con le sue chiavi, durano un'ora, e valgono solo su questo banco.
//
// Quello che si prova qui non e' il modulo di accesso, che e' di WordPress e
// funziona, ma cosa vede e cosa non vede chi e' gia' entrato.

$login  = $args[0] ?? 'oxyadmin';
$utente = get_user_by( 'login', $login );

if ( ! $utente ) {
	echo "utente {$login} inesistente\n";

	return;
}

$scadenza = time() + HOUR_IN_SECONDS;

$cookie = array(
	'wordpress_' . COOKIEHASH           => 'auth',
	'wordpress_sec_' . COOKIEHASH       => 'secure_auth',
	'wordpress_logged_in_' . COOKIEHASH => 'logged_in',
);

foreach ( $cookie as $nome => $scheme ) {
	echo $nome . "\t" . wp_generate_auth_cookie( $utente->ID, $scadenza, $scheme ) . "\n";
}
