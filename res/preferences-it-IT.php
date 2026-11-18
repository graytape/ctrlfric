<?php
$translations = array_merge($translations, [
    'Dark mode' => 'Modalità scura',
    'Dismiss' => 'Chiudi',
    'Monthly spending limit exceeded' => 'Limite di spesa mensile superato',
    'Spent' => 'Speso',
    'Entries' => 'Movimenti',
    'Spending and goals' => 'Spese e obiettivi',
    'Net expenses by category' => 'Spese nette per categoria',
    'Subtract income in the same category from expense charts. Minimum zero; income and balances stay unchanged.' => 'Sottrai le entrate della stessa categoria nei grafici delle spese, fino a un minimo di zero. Entrate e saldi restano invariati.',
    'First day of the accounting month' => 'Giorno di inizio del mese contabile',
    'Days 29–31 use the last day in shorter months. Periods are labeled by their starting month.' => 'Per i giorni 29–31, nei mesi più corti si usa l’ultimo giorno. Ogni periodo prende il nome del mese in cui inizia.',
    'Enable monthly spending goal' => 'Attiva obiettivo di spesa mensile',
    'Monthly spending limit' => 'Limite di spesa mensile',
    'The goal counts gross expenses in your accounting month, using your profile currency.' => 'L’obiettivo conteggia le uscite lorde del mese contabile, nella valuta del profilo.',
    'Save preferences' => 'Salva preferenze',
    'Spending preferences updated' => 'Preferenze di spesa aggiornate',
    'Choose a day from 1 to 31 and a positive budget when goals are enabled.' => 'Scegli un giorno da 1 a 31 e un limite maggiore di zero quando gli obiettivi sono attivi.',
    'Please enter a name, a valid email and a password.' => 'Inserisci nome, email valida e password.',
    'An account with this email already exists.' => 'Esiste già un account con questa email.',
    'Account creation failed. Please try again.' => 'Creazione dell’account non riuscita. Riprova.',
    'Contact the administrator to reset your password.' => 'Contatta l’amministratore per reimpostare la password.',
    'Please check the date, amount, currency and description.' => 'Controlla data, importo, valuta e descrizione.',
]);

$translations = array_merge($translations, [
    'Preferences' => 'Preferenze',
    'Preferences saved' => 'Preferenze salvate',
    'Please check your preferences.' => 'Controlla le preferenze inserite.',
    'Monthly goals by category' => 'Obiettivi mensili per categoria',
    'Set a monthly limit for each category, or leave it empty for no limit.' => 'Imposta un limite mensile per ogni categoria. Lascia il campo vuoto se non vuoi un limite.',
    'Limits count gross expenses in your accounting month.' => 'I limiti conteggiano le uscite lorde del tuo mese contabile.',
    'No limit' => 'Nessun limite',
    'Save goals' => 'Salva obiettivi',
    'Category goals saved' => 'Obiettivi per categoria salvati',
    'Enter a positive limit or leave the field empty.' => 'Inserisci un limite maggiore di zero oppure lascia il campo vuoto.',
]);

$translations = array_merge($translations, [
    'Monthly limit beside each name. Zero = inactive. Drag the handle to reorder.' => 'Limite mensile accanto al nome. Zero = inattivo. Trascina la maniglia per riordinare.',
    'Reorder' => 'Riordina',
    'Please check the category names and limits.' => 'Controlla i nomi delle categorie e i limiti.',
    'Use unique category names and limits of zero or more.' => 'Usa nomi di categoria distinti e limiti maggiori o uguali a zero.',
]);

$translations['Categories'] = 'Categorie';
$translations['General'] = 'Generale';
$translations['Password'] = 'Password';
$translations['Click category tags to filter transactions'] = 'Clicca sulle tag delle categorie per filtrare i movimenti';
$translations['Show edit and delete actions on hover (desktop)'] = 'Mostra modifica ed elimina solo al passaggio del mouse (desktop)';
$translations['Clear filter'] = 'Rimuovi filtro';

$translations = array_merge($translations, [
    'Forgot password?' => 'Password dimenticata?',
    'Exchange rate' => 'Tasso di cambio',
    'Optional' => 'Facoltativo',
    'Email / SMTP' => 'Email / SMTP',
    'Public application URL (including subdirectory)' => 'URL pubblico dell’app (inclusa sottocartella)',
    'Sender email' => 'Indirizzo mittente',
    'SMTP host (empty = PHP mail)' => 'Server SMTP (vuoto = mail PHP)',
    'SMTP port' => 'Porta SMTP',
    'SMTP username' => 'Utente SMTP',
    'Encryption' => 'Cifratura',
    'SMTP password (leave empty to keep)' => 'Password SMTP (vuoto per mantenere)',
    'Remove saved SMTP password' => 'Rimuovi la password SMTP salvata',
    'If SMTP fails, PHP mail() is used. Delivery depends on your hosting mail configuration.' => 'Se SMTP non funziona, viene usato mail() di PHP. La consegna dipende dalla configurazione dell’hosting.',
    'Please check the SMTP settings and public application URL.' => 'Controlla i parametri SMTP e l’URL pubblico dell’app.',
    'Reload the page and try again.' => 'Ricarica la pagina e riprova.',
    'Use at least 8 characters and matching passwords.' => 'Usa almeno 8 caratteri e due password uguali.',
    'Invalid or expired link.' => 'Link non valido o scaduto.',
    'Password updated. You can now log in.' => 'Password aggiornata. Ora puoi accedere.',
    'If the account exists, a password reset link will be sent.' => 'Se l’account esiste, riceverai un link per reimpostare la password.',
]);

$translations = array_merge($translations, [
    'Currencies' => 'Valute',
    'Fetch exchange rates automatically for each new entry' => 'Recupera automaticamente il cambio per ogni nuovo movimento',
    'Rates are saved with each new entry. Changing defaults never changes earlier entries.' => 'Il cambio viene salvato in ogni nuovo movimento. Modificare i valori predefiniti non cambia i movimenti precedenti.',
    'Frankfurter supplies the latest available daily rate. On weekends or holidays the publication date may be earlier.' => 'Frankfurter fornisce l’ultimo tasso giornaliero disponibile. Nei fine settimana e nei festivi la data di pubblicazione può essere precedente.',
    'Account reference currency' => 'Valuta di riferimento dell’account',
    'Save currencies' => 'Salva valute',
    'Update latest exchange rates' => 'Aggiorna tassi di corrispondenza odierni',
    'Exchange rates updated.' => 'Tassi di cambio aggiornati.',
    'Rates unavailable. Please try again.' => 'Tassi non disponibili. Riprova.',
    'Some rates are unavailable. No rates were changed.' => 'Alcuni tassi non sono disponibili. Nessun tasso è stato modificato.',
    'Set a positive default rate for each active currency.' => 'Imposta un tasso predefinito positivo per ogni valuta attiva.',
    'Invalid currency.' => 'Valuta non valida.',
    'Enable this currency in Preferences first.' => 'Abilita prima questa valuta nelle preferenze.',
    'Please enter a valid exchange rate.' => 'Inserisci un tasso di cambio valido.',
    'Set an exchange rate in Preferences or enable automatic rates.' => 'Imposta il cambio nelle preferenze o attiva i tassi automatici.',
    'Loading exchange rate…' => 'Recupero del tasso di cambio…',
]);

$translations=array_merge($translations,[
'Unauthorized'=>'Accesso non autorizzato',
'Missing parameters'=>'Parametri mancanti',
'Delete failed'=>'Eliminazione non riuscita',
'Update failed'=>'Aggiornamento non riuscito',
'Invalid action'=>'Azione non valida',
'Invalid date range.'=>'Intervallo di date non valido.',
'Failed to save changes'=>'Salvataggio delle modifiche non riuscito',
'Failed to delete entry'=>'Eliminazione del movimento non riuscita',
'Are you sure you want to delete this entry?'=>'Vuoi eliminare questo movimento?',
'Please enter at least 3 characters to search'=>'Inserisci almeno 3 caratteri per cercare',
'Imported rows'=>'Righe importate',
'CSV rows with errors'=>'Righe CSV con errori',
'Error opening the CSV file.'=>'Impossibile aprire il file CSV.',
'View Expenses and Incomes'=>'Visualizza spese ed entrate',
'Expenses by macrocategory'=>'Spese per macrocategoria',
'Macro/Micro'=>'Macro/Micro',
'Password successfully updated'=>'Password aggiornata correttamente',
'Error updating password'=>'Errore durante l’aggiornamento della password',
'New passwords do not match'=>'Le nuove password non coincidono',
'Current password is incorrect'=>'La password attuale non è corretta',
'User deleted successfully'=>'Utente eliminato correttamente',
'Error deleting user'=>'Errore durante l’eliminazione dell’utente',
'Password reset to: '=>'Password reimpostata: ',
'Error resetting password'=>'Errore durante la reimpostazione della password',
'Macrocategories'=>'Macrocategorie',
'Import CSV'=>'Importa CSV',
'Excel → Save in .csv'=>'Excel → Salva in .csv',
'Created'=>'Creato il',
'Last Access'=>'Ultimo accesso',
'Reset password for this user?'=>'Reimpostare la password di questo utente?',
'Delete this user?'=>'Eliminare questo utente?',
'Account created successfully'=>'Account creato correttamente',
'Invalid email or password.'=>'Email o password non corretti.',
]);
