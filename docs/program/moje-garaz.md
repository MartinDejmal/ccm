# Moje garáž

Implementovaný stav k 21. 10. 2026. Po přihlášení se otevře garáž s kartami vlastních vozidel. Filtr přepíná aktivní, archivovaná nebo všechna vozidla. Z karty lze otevřít tankování a výdaje, upravit údaje nebo vozidlo archivovat.

## Založení a úprava

Tlačítko **Přidat vozidlo** otevře formulář: název, značka, model, registrační značka, VIN, datum pořízení, počáteční tachometr, jednotka vzdálenosti a výchozí měna. Při založení se vybere výchozí palivo / energie pro první nádrž nebo médium. Správa dalších médií zatím není součástí garáže.

Po uložení vozidla je možné nahrát obrázek. Údaje vozidla a obrázek mají vlastní tlačítko uložení; neuložené úpravy údajů zůstanou při změně obrázku ve formuláři.

Změna výchozí měny ani počátečního tachometru neupravuje existující finanční záznamy. Změnu km/míle server povolí pouze před vznikem historie nebo jakéhokoli CSV náhledu (včetně zrušeného); převod již uložených údajů není podporován. Měna se volí z dostupného číselníku. Změny chrání revize: při souběžné úpravě server vrátí konflikt a je třeba načíst aktuální údaje. Tlačítko **Načíst aktuální údaje** obnoví seznam a zavře rozpracovaný formulář.

## Archivace a obnovení

Archivaci je nutné potvrdit. Vozidlo, obrázek a celá historie zůstanou zachované. Historie archivovaného vozidla je pouze pro čtení; nelze přidat či změnit tankování/výdaj ani nahrát nový CSV náhled. Údaje vozidla a obrázek lze spravovat i v archivu. Starší CSV náhledy lze prohlížet a spravovat. Tlačítko **Obnovit vozidlo** opět povolí zápisy do historie.

Archivace není smazání a neznamená vrácení importu ani vyřazení finančních záznamů. Trvalé mazání vozidel není v této verzi dostupné.

## Obrázek / avatar auta

Povolené vstupy jsou JPEG, PNG a WebP, nejvýše 5 MiB, s šířkou i výškou 32–4096 px. HEIC, SVG a GIF nejsou podporované; fotografii z telefonu v HEIC je třeba nejprve převést na JPEG. Nahraný soubor se na serveru dekóduje, u JPEG se zohlední EXIF orientace, poměr stran se zachová a nejdelší strana se zmenší na nejvýše 1280 px. Výsledkem je WebP bez původních metadat (včetně GPS). Používejte statické obrázky. Výřez na kartě a čtvercový avatar používají stejný uložený obrázek.

Název souboru určuje server. Obrázky jsou neveřejné a získá je pouze přihlášený vlastník vozidla. Při nahrazení či odstranění se předchozí soubor smaže; audit zachovává informaci o operaci, nikoli kopie obrázků. Při konfliktu revize se nový soubor odstraní a původní obrázek zůstane zachovaný.

Soubory jsou v trvalém volume kontejneru. Samotná záloha DB nestačí: zálohujte i storage. Detaily konfigurace jsou v [provozním návodu](spusteni.md).

## API

Všechny operace vyžadují vlastníka a webovou session; změny také CSRF token. ID se přenášejí jako řetězce, přesné hodnoty jako desetinné řetězce. Úpravy vyžadují aktuální celočíselnou `revision`.

| Metoda a cesta pod /api/v1 | Význam |
|---|---|
| GET /vehicles | Seznam vlastních aktivních i archivovaných vozidel |
| POST /vehicles | Založení a výchozí médium |
| GET /vehicles/{id} | Aktuální údaje a revize |
| PUT /vehicles/{id} | Úprava údajů |
| PATCH /vehicles/{id}/archive | Archivace/obnovení: revision a boolean archived |
| GET /vehicles/{id}/avatar | Neveřejný WebP obrázek |
| POST /vehicles/{id}/avatar | Multipart: avatar a revision |
| DELETE /vehicles/{id}/avatar | Odstranění: revision |

Vozidlo vrací `avatar_url` (nebo null), nikoli interní cestu k souboru. Neexistující či cizí objekt vrací 404, neplatná data 422 a konflikt revize nebo zápis do archivované historie 409. Audit vozidel je v `vehicle_revisions`; jeho zobrazení ve webu zůstává ve frontě.
