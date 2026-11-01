# WS40 Broadcaster 1.3.1

Plugin WordPress per inviare bollettini meteorologici WS40, previsioni Open-Meteo e immagini webcam a Telegram, con banner HTML personalizzabile.

## Requisiti
- WordPress 6.2 o superiore; PHP 7.4 o superiore (intestazione del plugin).
- WeatherStation40 2.7.37, attivo con almeno una stazione abilitata (requisito documentato nel progetto).
- Accesso a Telegram e, per le previsioni, al servizio Open-Meteo.

## Installazione e aggiornamento
1. Eseguire un backup dei file e del database.
2. In WordPress, aprire **Plugin → Aggiungi nuovo → Carica plugin** e scegliere lo ZIP ufficiale.
3. Se WS40 Broadcaster è già installato, scegliere **Sostituisci quello attuale con quello caricato**. Non disinstallarlo preventivamente.
4. Controllare le impostazioni esistenti, la stazione selezionata, il token e il destinatario Telegram, gli orari e le impostazioni webcam.
5. Verificare un invio manuale e il successivo invio programmato (WP-Cron).

### Migrazione dal vecchio plugin
Se si proviene da Cervignano Meteo Telegram, disattivarlo prima di attivare WS40 Broadcaster per evitare invii duplicati. Il plugin legge le impostazioni precedenti da `cmt_telegram_settings`. Non eliminare il vecchio plugin prima di aver verificato la migrazione.

## Funzioni
- Selezione delle stazioni WS40 abilitate.
- Bollettini meteorologici su Telegram.
- Previsioni Open-Meteo.
- Foto webcam su Telegram.
- Banner HTML personalizzabile e shortcode.
- Interfaccia e messaggi Telegram (bollettini, previsioni, didascalia webcam) localizzati in italiano (sorgente), inglese USA/UK, tedesco, olandese, spagnolo, portoghese PT/BR e francese: i messaggi usano la lingua del sito.
- Orari, date e unità (metriche o imperiali) seguono la stazione WS40 selezionata; le previsioni Open-Meteo usano le stesse unità.

## Traduzioni della community
Consulta `TRANSLATING.md` e il modello `languages/ws40-broadcaster.pot` per creare una traduzione con Poedit. Puoi inviare i file PO tramite una pull request GitHub o allegarli alla discussione dedicata sul forum della community. Prima di includere una traduzione in un rilascio ufficiale verrà verificata.

## Distribuzione
Distribuito tramite GitHub e forum della community WS40, non tramite WordPress.org. Usa soltanto i pacchetti pubblicati nei canali ufficiali del progetto.

## Note
- WhatsApp non è implementato.
- Gli invii automatici dipendono dal funzionamento di WP-Cron; per una maggiore puntualità è consigliato un cron server.

## Novità
### 1.3.1
- Messaggi Telegram traducibili: bollettino, previsioni e didascalia webcam usano la lingua del sito, anche quando la prova parte da un amministratore con un'altra lingua.
- Fuso orario della stazione WS40 (o del sito, se la stazione non ne ha uno) per orari programmati, date e ore dei messaggi, al posto di Europe/Rome fisso.
- Unità della stazione: °F, mph, in e inHg sulle stazioni imperiali, anche nelle previsioni Open-Meteo.
- Numeri con il separatore decimale della lingua, senza separatore delle migliaia.

### 1.3.0
- Prima versione pubblica.
