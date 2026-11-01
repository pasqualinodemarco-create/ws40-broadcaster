# Contribuire alle traduzioni di WS40 Broadcaster

Grazie per voler tradurre WS40 Broadcaster nella tua lingua!

## Crea una nuova traduzione
1. Scarica il pacchetto ufficiale da GitHub o dal forum della community WS40.
2. Installa **Poedit** (https://poedit.net/) e apri `languages/ws40-broadcaster.pot` tramite **Crea nuova traduzione**.
3. Scegli la lingua e il codice WordPress: per il ceco `cs_CZ`, per il norvegese Bokmål `nb_NO`, per il norvegese Nynorsk `nn_NO`.
4. Traduci tutte le stringhe. Non modificare segnaposto come `%s`, `%d`, nomi di shortcode, tag HTML, URL o nomi di funzioni.
5. Salva il catalogo come `ws40-broadcaster-<locale>.po` (per esempio `ws40-broadcaster-cs_CZ.po`). Poedit genera normalmente anche il file `.mo`.
6. Imposta la lingua corrispondente in WordPress e prova l'interfaccia amministrativa e i messaggi generati dal plugin.
7. Invia una pull request al repository GitHub ufficiale, aggiungendo `.po` e `.mo`, oppure allega i file a una discussione nel forum della community. Indica lingua, variante e versione del plugin.

## Come provare una traduzione
Per una prova locale, carica `.po` e `.mo` nella cartella `wp-content/plugins/ws40-broadcaster/languages/`. Questi file potrebbero essere sovrascritti dagli aggiornamenti. Conserva una copia e invia la traduzione al progetto per includerla nel prossimo rilascio ufficiale.

## Revisione
I contributi vengono verificati per completezza, terminologia meteorologica, segnaposto, HTML, coerenza e visualizzazione su WordPress. Una traduzione può richiedere modifiche prima di essere accettata.

## Nota sui contenuti personalizzati
I testi personalizzati salvati dall'amministratore, ad esempio nel banner Telegram, non vengono tradotti automaticamente dai cataloghi del plugin.
