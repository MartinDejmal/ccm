# Opakované výdaje

Záložka **Plány** zobrazuje aktivní nebo ukončené plány vybraného vozidla, seřazené podle dalšího termínu. Odhad částky a měna vycházejí ze zdrojového skutečného výdaje. Plán ani odhad se nezapočítávají do finančních součtů.

## Založení

U nového nebo upravovaného výdaje vyberte nenulový interval a zaškrtněte **Výslovně vytvořit plán opakovaných plateb**. Samotné nastavení intervalu plán nevytváří. Z jednoho zdrojového výdaje může vzniknout právě jeden plán, včetně později ukončeného plánu. Při úpravě zdrojového výdaje uvidíte číslo existujícího plánu.

V CSV náhledu má každý zahrnutý výdaj s nenulovým intervalem vlastní předem nezaškrtnutou volbu vytvoření plánu. Volby se při změně revize náhledu vynulují; vyberte je až po vyřešení přeskočení a duplicit. Samotné historické intervaly nevytvářejí žádné plány. Vyberte například jen poslední platbu daného pojištění. Starý kotevní termín může znamenat, že první další termín je již v minulosti. Import výdajů a vybraných plánů proběhne v jedné transakci.

## Termíny a platby

Podporované intervaly jsou 1, 3, 6, 12, 24 a 36 měsíců. Kotvou je datum zdrojového výdaje. Kratší měsíc použije poslední den: 31. leden → 28./29. únor → 31. březen. Přestupný rok se zachová i u ročních intervalů.

**Zapsat platbu** otevře běžný formulář výdaje s předvyplněným odhadem, kategorií, měnou a odkazem na termín plánu. Upravte skutečné datum a cenu a uložte. Teprve uložení skutečného výdaje současně posune plán o jedno období. Zrušení formuláře plán nezmění. Opožděná platba nepřeskakuje několik nezaplacených období. Další platby se nikdy nevytvářejí automaticky. Stejný požadavek odeslaný opakovaně nevytvoří další výdaj ani neposune plán znovu; konkurenční změna plánu vrátí konflikt a vyžaduje nové načtení.

Oprava propojené platby její termín znovu neposouvá. Vazbu na plán a pokrytý termín uvidíte v editoru výdaje. Již existující výdaj se v tomto kroku dodatečně nepřiřazuje jako platba plánu; import zůstává evidencí historie, nikoli automatickým párováním plateb.

## Úpravy a ukončení

V plánu lze upravit název a interval nebo jej výslovně ukončit. Změna intervalu zachová původní kotevní datum a určí první termín nového intervalu po posledním pokrytém termínu. Změna data či intervalu zdrojového výdaje již založený kalendář neposune; upravte plán samostatně. Oprava zdrojové částky, měny nebo kategorie aktualizuje odhad pro příští platbu. Dosavadní skutečné platby se nemění.

Vrácení importu ukončí aktivní plán, jehož zdroj byl skutečně vyřazen. Náhled vrácení uvede počet dotčených plánů a změna plánu po náhledu vyžaduje novou kontrolu. U ukončeného plánu zůstane důvod i audit. Pokud se upravený zdroj při částečném vrácení ponechá, jeho plán se neukončí. Smazání zdrojového výdaje nebo propojené platby v historii rovněž ukončí aktivní plán s důvodem a auditem; ostatní skutečné platby se nemění.

Archivované vozidlo dovoluje plány prohlížet, ale blokuje jejich změny i zápis plateb. Plány a platby jsou přístupné pouze vlastníkovi vozidla. Ukončený plán se zobrazuje v historii a v této verzi se znovu neaktivuje. E-mailové/push notifikace zatím nejsou součástí aplikace.

## Datový a API kontrakt

Migrace `2026_10_22_000002_create_recurring_expense_plans` přidává `recurring_expense_plans`, vazby `recurring_expense_payments` (jedinečný skutečný výdaj a jedinečný termín plánu) a audit `recurring_expense_revisions`. Revize a zámek vozidla chrání všechny zápisy včetně plateb a vrácení importu. Částka zůstává pouze u skutečné finanční události; plán nemá vlastní zaplacenou částku.

- `GET /api/v1/vehicles/{vehicle}/plans?status=active|ended|all&page=1`: stránkovaný přehled, 25 plánů na stránku; výchozí aktivní.
- `POST …/plans`: `source_event_id`, `source_revision`, volitelný `name`; zdroj musí být výdaj téhož vozidla s nenulovým intervalem.
- `PUT …/plans/{plan}`: `revision`, `name`, `interval_months`, volitelně `end: true`.
- Vytvoření/úprava běžného výdaje: volitelné `create_plan: true`, jinak se nic neplánuje.
- Vytvoření skutečné platby: běžný payload výdaje a `plan_id`, `plan_revision`, `plan_due_on`; pouze při vytvoření nového výdaje, s obvyklým `request_key`.
- Potvrzení importu: volitelné `plan_row_ids` identifikující výslovně vybrané zahrnuté řádky dané dávky; výchozí prázdné.
