# Spotřeba a kvalita historie

Implementovaný krok 9 k 22. 10. 2026. Otevřete historii vozidla a záložku **Spotřeba**. Přehled ukazuje výsledek po jednotlivých nádržích/médiích, použité množství a vzdálenost, hranice intervalů a důvody nedostupného výpočtu. Podklady jsou stránkované po 25 záznamech, nejnovější jsou nahoře.

## Plná–plná

Plné tankování A s doloženým tachometrem vytvoří výchozí bod. Jeho vlastní množství se do následujícího intervalu nezapočítává. Sečtou se až následující částečná tankování a uzavírací plné tankování B. Vzdálenost je tachometr B minus tachometr A.

`spotřeba = 100 × součet množství mezi A a B včetně B / vzdálenost A–B`

Příklad: A na 1 000 km, následně částečných 10 l a plných 20 l při 1 500 km. Interval má 30 l / 500 km a spotřebu 6 l/100 km. Při dalším plném tankování 10 l na 1 600 km vznikne interval 10 l / 100 km. Vážený průměr je **6,6667 l/100 km** (40 l / 600 km), nikoli aritmetický průměr hodnot 6 a 10.

Všechny součty a dělení používají přesnou desetinnou aritmetiku. API vrací hodnoty jako desetinné řetězce, výslednou sazbu zaokrouhluje na čtyři desetinná místa metodou HalfUp. Vstupní množství ani tachometry se tím nemění. Přehled rozlišuje nakoupené/dodané množství v období a množství použité pro úplné intervaly; první plná nádrž a neúplné úseky proto nejsou skrytě zahrnuté do průměru.

## Typ tankování a přerušení

| Situace | Chování |
|---|---|
| Plné (1) | Založí výchozí bod nebo uzavře interval |
| Částečné (2) | Množství čeká na následující plné tankování |
| První (3) | Zahájí novou historii; u plná–plná samo nepotvrzuje plnou nádrž |
| Neplatné (0) | Přeruší řetězec; náklad a dodané množství zůstávají evidované |
| Chybějící tachometr | Přeruší řetězec i u částečného tankování; Trip ho nenahrazuje |
| Pokles tachometru | Předchozí interval se nepoužije; aktuální platný plný odečet může založit nový řetězec |
| Nulová vzdálenost mezi hranicemi | Výsledek není platný; aktuální plný odečet začne nový řetězec |
| Nesrovnatelná jednotka nebo médium | Žádný automatický převod ani výpočet přes daný záznam |
| Vyřazené tankování | Množství je mimo aktivní dodávky a jeho místo v historii přeruší výpočet |

Pokud víte o chybějícím tankování, upravte následující záznam a označte **Před tímto tankováním chybí část historie**. Předchozí otevřený interval se tím zneplatní; je-li tento záznam platný plný odečet, lze od něj začít nový řetězec. Neoznámené chybějící nákupy nelze spolehlivě poznat jen z čísel, neodhadují se podle délky mezery v kalendáři.

U čekajících částečných tankování je vidět, kterým záznamem byl interval uzavřen či přerušen. Otevřený konec historie má vlastní upozornění a nevytváří nulovou ani odhadovanou spotřebu.

## Více tankování v jednom dni

Při více záznamech pro jednu nádrž a den musí mít každý explicitní, kladné a různé **pořadí tankování ve stejném dni**. Hodnota 0 znamená neurčeno. Číslo není čas a nemusí být souvislé. Pořadí se neurčuje z ID záznamu, pořadí CSV ani řazením tachometrů.

Pokud pořadí chybí nebo je duplicitní, den se nepoužije pro výpočet a řetězec se přeruší. Vyřešte pořadí úpravou jednotlivých tankování, například 1 a 2. Kontroluje se v rámci stejné nádrže; ostatní média lze řadit nezávisle. Číslo obsazené vyřazeným záznamem se znovu nepoužívá. Nejasný den s vyřazenou historií může vyžadovat novou doloženou kotvu v následujícím jednoznačném dni; obnova vyřazených záznamů ve webu zatím není dostupná.

Úpravy pořadí a označení mezery používají stejné revize, vlastnictví a audit jako ostatní úpravy tankování. Starší klient, který tyto položky při úpravě nepošle, zachová jejich uložené hodnoty.

## Období a pokrytí

Filtry **Spotřeba od data**, **Spotřeba do data** a nádrž/médium se potvrzují tlačítkem **Zobrazit spotřebu**. Hranice dat jsou včetně zvoleného dne. Výpočet načítá i potřebný starší kontext, aby začátek filtru nevytvořil falešnou plnou nádrž.

Do průměru se zahrnou jen celé platné intervaly s oběma hranicemi uvnitř období. Interval končící v období, ale začínající před ním, se zobrazí s vysvětlením a vlastní hodnotou, avšak do průměru se nezahrne. Množství se poměrově nerozděluje mezi měsíce. Hranice pokrytí označují nejstarší začátek a poslední konec započtených intervalů; vzdálenost je součet jejich vzdáleností, nikoli nepřerušený rozdíl přes případné mezery.

Filtry měny, kategorie výdaje a druhu paliva z finanční historie se na výpočet nepřenášejí. Vynechání prostředního tankování by zkreslilo spotřebu. Nádrže se počítají samostatně, včetně neaktivních nádrží s historickými daty. Litry, kilogramy, kWh, km a míle se nesčítají ani automaticky nepřevádějí.

## Elektřina a AdBlue

Pro elektřinu a AdBlue se používá **dodané množství na doloženou vzdálenost**. Nikdy se nevydává za spotřebu baterie, spotřebu nafty ani měření z palubního počítače. U elektřiny nejsou známé stavy nabití a ztráty; příznak „plná“ tuto neznalost neřeší.

Poměr používá následující dodávky mezi spolehlivými odečty tachometru; množství na výchozí hranici se vyloučí. První platný odečet může založit hranici i u typu 2 či 3, následující dodávka s kladnou vzdáleností uzavře úsek. Nulová vzdálenost, chybějící tachometr a přerušení mají stejné konzervativní zacházení jako výše. Průměr je opět poměr součtu množství a součtu doložených vzdáleností.

Příklad: dodávky 10 kWh na 1 000 km, 20 kWh na 1 100 km a 60 kWh na 1 300 km dávají přehled 80 kWh / 300 km, tedy 26,6667 kWh/100 km. Prvních 10 kWh je mimo doložený úsek. Skutečná spotřeba baterie z těchto údajů dostupná není. AdBlue má vlastní přehled a nijak nezmění výsledek naftové nádrže.

## Importované a palubní údaje

Každý podklad uvádí samostatně **vypočtenou spotřebu**, původní **Consumption** a palubní **BC-Consumption** s jednotkou zdroje. Importované nuly se zobrazí podle již zvoleného CSV profilu. Původní hodnoty se výpočtem nepřepisují ani neprůměrují dohromady s výpočtem.

Pokud se zdrojový Trip liší od rozdílu sousedních spolehlivých tachometrů o více než 1 jednotku vzdálenosti, zobrazí se varování. Vzorec nadále používá tachometry, nikoli Trip. Ceny a množství se neodvozují z poznámek.

## API, uložení a aktualizace

`GET /api/v1/vehicles/{vehicle}/consumption` vyžaduje přihlášeného vlastníka. Parametry: volitelné `from`, `to` (YYYY-MM-DD), `energy_stream_id` a `page`. Odpověď obsahuje:

- `streams`: nezávislé souhrny, metoda, jednotky, průměr nebo null, použité množství/vzdálenost, počet intervalů, pokrytí, otevřený konec a počty důvodů kvality;
- `observations`: stránkované podklady s ID, důvodem, zdrojovými hodnotami, případným vypočteným intervalem, hranicemi, zahrnutými ID a příznakem započtení do průměru.

Neexistující nebo cizí vozidlo vrací 404, nesprávné filtry 422, nepřihlášený 401. Čtení funguje i pro archivované vozidlo; upravovat ho lze až po obnovení v garáži.

Migrace `2026_10_22_000001_add_consumption_history_break` přidává `fuelings.break_before` s výchozí hodnotou false. Používá se již existující `vehicle_events.day_sequence`. Příznak mezery a pořadí jsou interní metadata, která původní CSV o 22/7 sloupcích neobsahuje.

Výsledky jsou odvozené při čtení, bez trvalé cache a bez zápisu do finančních dat. Změna data, tachometru, množství, typu, pořadí či média, import, vyřazení, obnova dat nebo vrácení dávky se projeví při dalším načtení. Tlačítko **Přepočítat** obnoví přehled po změně v jiné záložce či zařízení. Samostatná webová obnova záznamů, správa dalších médií, CSV export a grafy zůstávají mimo tuto etapu.
