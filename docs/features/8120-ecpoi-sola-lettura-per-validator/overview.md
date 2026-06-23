> Ticket: oc:8120

# EcPoi: sola lettura per Validator

## Cosa cambia
I Validator (gestori di cammino) non potranno più creare, modificare o eliminare EcPoi tramite Nova. Potranno solo visualizzare l'indice e il dettaglio. Gli Administrator mantengono pieno accesso. I Guest, che accedono esclusivamente via API, vengono bloccati a livello Nova (`viewAny → false`).

## Perché
In camminiditalia i punti di interesse (EcPoi) sono gestiti centralmente dagli Administrator. I Validator devono poterli consultare per il proprio lavoro, ma non modificarli — è una scelta di governance dei dati specifica di questo progetto.

## Requisiti
- [ ] Un Validator non può creare EcPoi (policy `create → false`, pulsante Nova nascosto)
- [ ] Un Validator non può modificare un EcPoi (policy `update → false`, pulsante Edit nascosto)
- [ ] Un Validator non può eliminare un EcPoi (policy `delete → false`, pulsante Delete nascosto)
- [ ] Un Validator può visualizzare l'indice degli EcPoi (`viewAny → true`)
- [ ] Un Validator può aprire il dettaglio di un EcPoi (`view → true`)
- [ ] Le action di modifica (`ExecuteEcPoiDataChainAction`, `UploadPoiFile`, `TranslateModelAction`) sono nascoste ai Validator
- [ ] Le action di sola lettura/export (`DownloadEcPoiAction`) restano accessibili ai Validator
- [ ] I Guest sono bloccati completamente in Nova (`viewAny → false`, `view → false`)
- [ ] Test Feature che verificano 403 su create/update/delete per Validator e accesso al detail

## Rischi
- La policy del package (`wm-package/EcPoiPolicy`) attualmente concede `update/delete` a chi è `user_id` dell'EcPoi. Se esistono EcPoi con `user_id` di un Validator, dopo questa feature quell'utente perderà l'accesso — da verificare in fase di test.
- Le action Nova filtrate per `canSee` producono 404 (non 403) quando chiamate via API — comportamento noto nel progetto (vedi decisione architetturale oc:8089 in CLAUDE.md).

## Out of scope
- Accesso API (route non Nova): i Validator mantengono i permessi esistenti sulle API REST
- Modifica dei permessi su EcTrack (ticket separato se necessario)
- Modifica della policy nel package `wm-package` (questa è una customizzazione locale)

## Moduli toccati
| File | Azione | Repo |
|------|--------|------|
| `app/Policies/EcPoiPolicy.php` | Creare | principale |
| `app/Providers/AppServiceProvider.php` | Modificare (aggiungere `Gate::policy`) | principale |
| `app/Nova/EcPoi.php` | Modificare (aggiungere `authorizedToCreate`, `authorizedToUpdate`, `authorizedToDelete`, override `actions()`) | principale |
| `tests/Feature/EcPoiPolicyTest.php` | Creare | principale |
