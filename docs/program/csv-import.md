# CSV import: kontrola, potvrzení a vrácení

Implementovaný stav k 21. 10. 2026 (kroky 7 a 8). [Formát souborů](csv-import-export.md) zůstává společným cílem importu a budoucího exportu. Export je následující bod roadmapy; v této verzi ještě není dostupný.

## Běžný postup

1. Otevřete historii vozidla a záložku **CSV import**. Vyberte tankování nebo ostatní výdaje, profil uvozovek a desetinný oddělovač. Potvrďte vozidlo a jednotky; u tankování vyberte odpovídající nádrž/médium.
2. Nahrajte CSV. Zůstávají limity 2 MiB / 5 000 řádků, UTF-8, jedna nádrž na soubor a žádné převody jednotek. Náhled se uloží, finanční historie se tím ještě nemění.
3. Projděte chyby a varování. Chybové řádky opravte v novém souboru nebo je přeskočte. Každý zahrnutý řádek musí být bez chyby. Nesmí být přeskočené všechny řádky.
4. Použijte **Ověřit duplicity a připravit potvrzení**. Server znovu zpracuje původní CSV podle uloženého profilu a ověří aktuální číselníky, jednotky, médium a shody s historií.
5. Každý řádek označený jako možná duplicita přeskočte, nebo výslovně zvolte **Zachovat jako další skutečný záznam**. Souhlas patří ke konkrétním aktuálním shodám. Změna řádků nebo odpovídajících záznamů v historii může vyžadovat nové rozhodnutí.
6. Zkontrolujte součty po měnách, zaškrtněte souhlas a stiskněte **Potvrdit import do historie**. Teprve nyní vzniknou tankování nebo výdaje, včetně pokročilých CSV hodnot, původu a auditu.

Potvrzení je atomické pro celou zahrnutou část dávky. Chyba zápisu neznamená částečně importovaný soubor. Opakování stejného potvrzení používá stejný klíč požadavku a nepřidá další události. Dvě současná potvrzení téhož nebo odpovídajících souborů se pro vozidlo serializují. Pokud mezitím vznikne nová shoda, server zápis zastaví a vyžádá novou kontrolu.

Potvrzenou dávku již nelze upravovat ani zrušit jako náhled. Její přehled uvádí čas potvrzení, počet importovaných a přeskočených řádků a původní součty. Každý řádek ukazuje ID události v historii; historie zobrazuje číslo importní dávky. Pozdější ruční úprava události zachovává odkaz na dávku a původní CSV.

## Co se považuje za možnou duplicitu

Jde o kandidáty k rozhodnutí, nikoli automatické mazání. Společným základem je stejné vozidlo, druh události, datum, měna a přesná částka. U výdajů se porovnává také kategorie; u tankování druh paliva, množství, jednotka a nádrž/médium. Poznámka a tachometr mohou být různé, aniž by se skryla možná druhá evidence stejné platby. Shody mezi různými vozidly se nehledají. Již vyřazené záznamy nejsou aktivní finanční historií a neblokují nový import.

Uvnitř souboru se pozdější zahrnutý řádek porovnává s předchozími zahrnutými řádky. Přeskočení prvního záznamu může proto odstranit kandidáta u druhého. Rozhraní ukazuje počet shod a nejvýše 20 ID z historie, respektive první podobný řádek souboru; kontrolní otisk zahrnuje všechny shody, nikoli jen zobrazený výběr.

Jiná částka, datum, kategorie nebo palivo se automaticky za shodu nepovažují. Před potvrzením je tedy stále potřeba zkontrolovat obsah a varování; nejde o fuzzy vyhledávání ani párování účtenek.

## Vrácení dávky

U potvrzené dávky zvolte **Zkontrolovat vrácení dávky**. Přehled oddělí nezměněné záznamy od těch, které byly po importu upraveny nebo již vyřazeny.

- Bez konfliktů lze po potvrzení vrátit celou dávku. Události se vratně vyřadí z aktivní historie; součty se přepočítají bez nich. Původní řádky CSV, detaily a audit zůstanou uložené.
- Při konfliktech se standardně nevyřadí nic. Lze výslovně zvolit ponechání změněných záznamů a vrácení pouze nezměněné části. Výsledek uvede počet vyřazených událostí a ID ponechaných konfliktů.
- Změna událostí po zobrazení přehledu vrácení operaci zastaví; je potřeba znovu zkontrolovat aktuální stav.

Vrácení je jedna transakce a opakované odeslání stejné operace je idempotentní. Nezasáhne ruční záznamy ani jiné dávky. Archivované vozidlo je potřeba před zápisem nebo vrácením obnovit v garáži.

Stav **Vráceno** je konečný i po částečném vrácení. Ponechané změněné záznamy zůstávají v historii; dávka se podruhé nespouští a nelze jí dodatečně vynutit jejich vyřazení. Samostatná obnova vyřazených událostí a zobrazení úplného auditu jsou stále ve frontě. Nový import stejného souboru se znovu kontroluje proti aktuální historii.

## API a uložení

Pod `/api/v1/vehicles/{vehicle}/imports/{batch}` jsou navíc k náhledu tyto operace:

| Operace | Účel / tělo |
|---|---|
| POST /review | Nová kontrola; revision |
| PATCH /rows/{row}/duplicate | Rozhodnutí; revision, duplicate_token, keep |
| POST /confirm | Zápis; revision, review_token, UUID request_key, acknowledged=true |
| GET /reversal | Aktuální přehled vrácení s review_token a konflikty |
| POST /revert | Vrácení; revision, review_token, UUID request_key, conflict_policy=abort/keep_changed, acknowledged=true |

Webové změny vyžadují session a CSRF; každý objekt je omezen na vlastníka vozidla. Konflikty vracejí 409 a neplatné vstupy 422. Potvrzení ani vrácení se nespouští přes GET. Klíč opakované operace se smí použít pouze pro stejný obsah. Revize chrání souběžné úpravy; otisk kontroly navíc chrání změny historie mezi náhledem a zápisem. Pro řádkové přeskočení a zrušení posílá současný web také revision; starší náhledové klienty API zatím přijímá i bez ní, ale každá taková změna zneplatní připravené potvrzení.

Migrace `2026_10_21_000003_complete_csv_imports` rozšiřuje dávky a řádky o stav potvrzení, revize a kontrolní otisky, přidává vazby `vehicle_events.import_batch_id/import_row_id` a audit `import_actions`. Finanční změny mají samostatné snímky v `event_revisions`. Částky a součty zůstávají přesné desetinné hodnoty; různé měny se nesčítají. Starší uložené náhledy zůstávají dostupné a před potvrzením vyžadují novou kontrolu.
