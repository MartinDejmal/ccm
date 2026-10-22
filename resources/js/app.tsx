import React, { useEffect, useState, useRef, FormEvent } from "react";
import { createRoot } from "react-dom/client";
import { Garage, VehicleImage } from "./components/Garage";
import { ConsumptionPanel } from "./components/ConsumptionPanel";
import { ImportPanel } from "./components/ImportPanel";
import { createRequestKey } from "./lib/request-key";
type Row = Record<string, any>;
async function api(path: string, method = "GET", data?: Row | FormData) {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )!.content;
    const res = await fetch("/api/v1" + path, {
        method,
        headers: {
            Accept: "application/json",
            ...(data instanceof FormData
                ? {}
                : { "Content-Type": "application/json" }),
            "X-CSRF-TOKEN": token,
        },
        credentials: "same-origin",
        body:
            data instanceof FormData
                ? data
                : data
                  ? JSON.stringify(data)
                  : undefined,
    });
    if (res.status === 204) return null;
    const body = await res.json();
    if (!res.ok)
        throw new Error(
            Object.values(
                body.errors ?? { message: body.message ?? "Požadavek selhal" },
            )
                .flat()
                .join(" "),
        );
    return body;
}
function App() {
    const requestVersion = useRef(0);
    const [filters, setFilters] = useState({
        from: "",
        to: "",
        currency: "",
        category: "",
    });
    const [user, setUser] = useState<Row | null>(null),
        [checked, setChecked] = useState(false),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const [vehicles, setVehicles] = useState<Row[]>([]),
        [catalogs, setCatalogs] = useState<Row>({}),
        [vehicle, setVehicle] = useState(""),
        [streams, setStreams] = useState<Row[]>([]),
        [events, setEvents] = useState<Row[]>([]),
        [summary, setSummary] = useState<Row[]>([]);
    const [mode, setMode] = useState("garage"),
        [form, setForm] = useState<Row | null>(null),
        [editId, setEditId] = useState(""),
        [page, setPage] = useState(1),
        [lastPage, setLastPage] = useState(1);
    useEffect(() => {
        api("/me")
            .then(setUser)
            .catch(() => {})
            .finally(() => setChecked(true));
    }, []);
    useEffect(() => {
        if (user)
            Promise.all([api("/vehicles"), api("/catalogs")])
                .then(([v, c]) => {
                    setVehicles(v);
                    setCatalogs(c);
                    if (v.length) setVehicle(v[0].id);
                })
                .catch((e) => setError(e.message));
    }, [user]);
    async function reload() {
        const version = ++requestVersion.current;
        if (!vehicle || mode === "garage") return;
        if (mode === "import" || mode === "consumption") {
            const t = await api(`/vehicles/${vehicle}/streams`);
            if (version === requestVersion.current) setStreams(t);
            return;
        }
        const params = new URLSearchParams({
            kind: mode,
            page: String(page),
            from: filters.from,
            to: filters.to,
            currency: filters.currency,
        });
        if (filters.category)
            params.set(
                mode === "expense" ? "expense_type_id" : "fuel_sort_id",
                filters.category,
            );
        const result = await Promise.all([
            api(`/vehicles/${vehicle}/events?${params}`),
            api(`/vehicles/${vehicle}/summary?${params}`),
            api(`/vehicles/${vehicle}/streams`),
        ]).catch((e) => {
            if (version === requestVersion.current) {
                setEvents([]);
                setSummary([]);
                throw e;
            }
            return null;
        });
        if (result === null || version !== requestVersion.current) return;
        const [e, s, t] = result;
        setEvents(e.data);
        setLastPage(e.last_page);
        setSummary(s);
        setStreams(t);
    }
    useEffect(() => {
        setError("");
        reload().catch((e) => setError(e.message));
    }, [vehicle, mode, page, filters]);
    const selected = vehicles.find((v) => v.id === vehicle);
    async function run(action: () => Promise<void>) {
        setError("");
        setBusy(true);
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    const money = (s: string) =>
        new Intl.NumberFormat("cs-CZ", { maximumFractionDigits: 2 }).format(
            Number(s),
        );
    function input(
        label: string,
        name: string,
        type = "text",
        required = false,
    ) {
        return (
            <label>
                {label}
                <input
                    name={name}
                    type={type}
                    required={required}
                    value={form?.[name] ?? ""}
                    onChange={(e) =>
                        setForm({ ...form, [name]: e.target.value })
                    }
                    step={type === "number" ? "any" : undefined}
                />
            </label>
        );
    }
    function select(
        label: string,
        name: string,
        rows: Row[],
        key = "id",
        display = "name",
    ) {
        return (
            <label>
                {label}
                <select
                    required
                    value={form?.[name] ?? ""}
                    onChange={(e) =>
                        setForm({ ...form, [name]: e.target.value })
                    }
                >
                    <option value="">Vyberte…</option>
                    {rows.map((r) => (
                        <option key={r[key]} value={r[key]}>
                            {r[display]}
                        </option>
                    ))}
                </select>
            </label>
        );
    }
    function newEntry() {
        setEditId("");
        setForm({
            request_key: createRequestKey(),
            kind: mode,
            occurred_on: new Date().toLocaleDateString("sv-SE"),
            currency_code: selected?.currency_code ?? "CZK",
            fueling_type_id: 1,
            energy_stream_id: streams[0]?.id ?? "",
            fuel_sort_id: "",
            repetition_months: 0,
            day_sequence: 0,
            break_before: false,
        });
    }
    async function save(e: FormEvent) {
        e.preventDefault();
        await run(async () => {
            const data = { ...form };
            for (const k of Object.keys(data))
                if (data[k] === "") data[k] = null;
            await api(
                `/vehicles/${vehicle}/events${editId ? "/" + editId : ""}`,
                editId ? "PUT" : "POST",
                data,
            );
            setForm(null);
            setEditId("");
            await reload();
        });
    }
    if (!checked) return <main>Načítání…</main>;
    if (!user)
        return (
            <main className="login">
                <span className="badge">CCM / CAR COST MANAGEMENT</span>
                <h1>
                    Vaše auto.
                    <br />
                    Jasné náklady.
                </h1>
                <p>Tankování, servis a výdaje na jednom místě.</p>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        run(async () => {
                            await api("/login", "POST", Object.fromEntries(d));
                            location.reload();
                        });
                    }}
                >
                    <h2>Přihlášení</h2>
                    <label>
                        E-mail
                        <input
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                        />
                    </label>
                    <label>
                        Heslo
                        <input
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            required
                        />
                    </label>
                    {error && (
                        <p role="alert" className="error">
                            {error}
                        </p>
                    )}
                    <button disabled={busy}>Přihlásit se</button>
                </form>
            </main>
        );
    return (
        <>
            <header>
                <a href="/" className="logo">
                    ccm<span> / náklady na auto</span>
                </a>
                <button
                    className="secondary"
                    onClick={() =>
                        run(async () => {
                            await api("/logout", "POST");
                            location.reload();
                        })
                    }
                >
                    Odhlásit
                </button>
            </header>
            <main>
                <div className="heading">
                    <div>
                        <span className="badge">VAŠE GARÁŽ</span>
                        <h1>
                            {mode === "garage"
                                ? "Moje garáž"
                                : "Přehled provozu"}
                        </h1>
                    </div>
                    <button
                        className={mode === "garage" ? "active" : "secondary"}
                        onClick={() => {
                            setMode("garage");
                            setForm(null);
                        }}
                    >
                        Moje garáž
                    </button>
                </div>
                {mode !== "garage" && (
                    <label className="vehicle">
                        Vozidlo
                        <select
                            value={vehicle}
                            onChange={(e) => {
                                setVehicle(e.target.value);
                                setForm(null);
                                setPage(1);
                            }}
                        >
                            <option value="">Vyberte vozidlo</option>
                            {vehicles.map((v) => (
                                <option key={v.id} value={v.id}>
                                    {v.name}
                                    {v.archived_at ? " (archivované)" : ""}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {mode === "garage" && (
                    <Garage
                        vehicles={vehicles}
                        catalogs={catalogs}
                        api={api}
                        changed={(v) =>
                            setVehicles((current) =>
                                [
                                    ...current.filter((x) => x.id !== v.id),
                                    v,
                                ].sort(
                                    (a, b) =>
                                        Number(!!a.archived_at) -
                                            Number(!!b.archived_at) ||
                                        a.name.localeCompare(b.name, "cs"),
                                ),
                            )
                        }
                        open={(v) => {
                            setVehicle(v.id);
                            setMode("fueling");
                            setForm(null);
                            setPage(1);
                            setEvents([]);
                            setSummary([]);
                        }}
                        refresh={async () => {
                            setVehicles(await api("/vehicles"));
                        }}
                    />
                )}
                {error && (
                    <p role="alert" className="error">
                        {error}
                    </p>
                )}
                {selected && mode !== "garage" && (
                    <>
                        <div className="selected-vehicle">
                            <VehicleImage vehicle={selected} compact />
                            <div>
                                <h2>{selected.name}</h2>
                                <small>
                                    {[
                                        selected.make,
                                        selected.model,
                                        selected.registration_plate,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ")}
                                </small>
                            </div>
                        </div>
                        {selected.archived_at && (
                            <p className="notice">
                                Vozidlo je archivované. Historie je pouze pro
                                čtení. Pro další zápisy vozidlo obnovte v Mojí
                                garáži.
                            </p>
                        )}
                        {["fueling", "expense"].includes(mode) && (
                            <section className="stats">
                                {summary.map((s, i) => (
                                    <article key={i}>
                                        <small>
                                            {s.kind === "fueling"
                                                ? "Palivo a energie"
                                                : "Ostatní výdaje"}
                                        </small>
                                        <strong>
                                            {money(s.total)}{" "}
                                            <span>{s.currency_code}</span>
                                        </strong>
                                    </article>
                                ))}
                                {!summary.length && (
                                    <article>
                                        <small>
                                            Zvoleným filtrům neodpovídají žádné
                                            záznamy.
                                        </small>
                                    </article>
                                )}
                            </section>
                        )}
                        <nav>
                            {[
                                ["fueling", "Tankování"],
                                ["expense", "Výdaje"],
                                ["consumption", "Spotřeba"],
                                ["import", "CSV import"],
                            ].map(([k, n]) => (
                                <button
                                    className={
                                        mode === k ? "active" : "secondary"
                                    }
                                    key={k}
                                    onClick={() => {
                                        setMode(k);
                                        setFilters({
                                            ...filters,
                                            category: "",
                                        });
                                        setForm(null);
                                        setPage(1);
                                    }}
                                >
                                    {n}
                                </button>
                            ))}
                            <button
                                disabled={
                                    busy ||
                                    !!selected.archived_at ||
                                    ["import", "consumption"].includes(mode) ||
                                    (mode === "fueling" &&
                                        !streams.some(
                                            (s) =>
                                                String(s.vehicle_id) ===
                                                vehicle,
                                        ))
                                }
                                onClick={newEntry}
                            >
                                + Nový záznam
                            </button>
                        </nav>
                    </>
                )}
                {selected && mode === "consumption" && (
                    <ConsumptionPanel
                        key={vehicle}
                        vehicle={selected}
                        streams={streams.filter(
                            (s) => String(s.vehicle_id) === vehicle,
                        )}
                        api={api}
                        edit={(id) =>
                            run(async () => {
                                const d = await api(
                                    `/vehicles/${vehicle}/events/${id}`,
                                );
                                setMode("fueling");
                                setForm({ ...d.detail, ...d.event });
                                setEditId(id);
                                setPage(1);
                            })
                        }
                    />
                )}
                {selected && mode === "import" && (
                    <ImportPanel
                        key={vehicle}
                        vehicle={selected}
                        streams={streams.filter(
                            (s) => String(s.vehicle_id) === vehicle,
                        )}
                        api={api}
                    />
                )}
                {selected && ["fueling", "expense"].includes(mode) && (
                    <section className="filters" aria-label="Filtry historie">
                        <div className="fields">
                            <label>
                                Od data
                                <input
                                    type="date"
                                    value={filters.from}
                                    onChange={(e) => {
                                        setFilters({
                                            ...filters,
                                            from: e.target.value,
                                        });
                                        setPage(1);
                                    }}
                                />
                            </label>
                            <label>
                                Do data
                                <input
                                    type="date"
                                    value={filters.to}
                                    onChange={(e) => {
                                        setFilters({
                                            ...filters,
                                            to: e.target.value,
                                        });
                                        setPage(1);
                                    }}
                                />
                            </label>
                            <label>
                                Měna
                                <select
                                    value={filters.currency}
                                    onChange={(e) => {
                                        setFilters({
                                            ...filters,
                                            currency: e.target.value,
                                        });
                                        setPage(1);
                                    }}
                                >
                                    <option value="">Všechny měny</option>
                                    {(catalogs.currencies ?? []).map(
                                        (c: Row) => (
                                            <option key={c.code} value={c.code}>
                                                {c.code}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label>
                                {mode === "expense"
                                    ? "Kategorie výdaje"
                                    : "Druh paliva"}
                                <select
                                    value={filters.category}
                                    onChange={(e) => {
                                        setFilters({
                                            ...filters,
                                            category: e.target.value,
                                        });
                                        setPage(1);
                                    }}
                                >
                                    <option value="">Všechny</option>
                                    {(mode === "expense"
                                        ? catalogs.expense_types
                                        : catalogs.fuel_sorts
                                    )?.map((c: Row) => (
                                        <option key={c.id} value={c.id}>
                                            {c.label ?? c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <p>
                            Historie i součty odpovídají těmto filtrům a vybrané
                            záložce.
                        </p>
                        <button
                            className="secondary"
                            onClick={() => {
                                setFilters({
                                    from: "",
                                    to: "",
                                    currency: "",
                                    category: "",
                                });
                                setPage(1);
                            }}
                        >
                            Zrušit filtry
                        </button>
                    </section>
                )}
                {form && (
                    <form className="editor" onSubmit={save}>
                        <h2>
                            {editId
                                ? "Upravit záznam"
                                : mode === "fueling"
                                  ? "Nové tankování"
                                  : "Nový výdaj"}
                        </h2>
                        <div className="fields">
                            {input("Datum", "occurred_on", "date", true)}
                            {input(
                                `Tachometr (${selected?.distance_unit})`,
                                "odometer",
                                "number",
                            )}
                            {input(
                                "Celková cena (záporně pro vratku)",
                                "amount",
                                "number",
                                true,
                            )}
                            {mode === "fueling" ? (
                                <>
                                    {select(
                                        "Nádrž / médium",
                                        "energy_stream_id",
                                        streams,
                                    )}
                                    {select(
                                        "Palivo",
                                        "fuel_sort_id",
                                        (catalogs.fuel_sorts ?? []).filter(
                                            (f: Row) =>
                                                f.medium_group ===
                                                streams.find(
                                                    (s) =>
                                                        String(s.id) ===
                                                        String(
                                                            form.energy_stream_id,
                                                        ),
                                                )?.medium_group,
                                        ),
                                    )}
                                    {input(
                                        `Množství (${streams.find((s) => String(s.id) === String(form.energy_stream_id))?.quantity_unit ?? ""})`,
                                        "quantity",
                                        "number",
                                        true,
                                    )}
                                    {select(
                                        "Typ tankování",
                                        "fueling_type_id",
                                        catalogs.fueling_types ?? [],
                                    )}
                                    <label>
                                        Pořadí tankování ve stejném dni (0 =
                                        neurčeno)
                                        <input
                                            name="day_sequence"
                                            type="number"
                                            min="0"
                                            max="10000"
                                            step="1"
                                            value={form.day_sequence ?? 0}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    day_sequence:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                        <small>
                                            Pokud je v nádrži více záznamů v
                                            jednom dni, určete všem různé pořadí
                                            1, 2, …
                                        </small>
                                    </label>
                                    <label className="check">
                                        <input
                                            name="break_before"
                                            type="checkbox"
                                            checked={!!form.break_before}
                                            onChange={(e) =>
                                                setForm({
                                                    ...form,
                                                    break_before:
                                                        e.target.checked,
                                                })
                                            }
                                        />
                                        Před tímto tankováním chybí část
                                        historie.
                                    </label>
                                </>
                            ) : (
                                <>
                                    {select(
                                        "Kategorie",
                                        "expense_type_id",
                                        catalogs.expense_types ?? [],
                                        "id",
                                        "label",
                                    )}
                                    {select(
                                        "Opakování (měsíce, bez založení plánu)",
                                        "repetition_months",
                                        catalogs.repetition_intervals ?? [],
                                    )}
                                </>
                            )}
                            {select(
                                "Měna",
                                "currency_code",
                                catalogs.currencies ?? [],
                                "code",
                                "code",
                            )}
                        </div>
                        <label>
                            Poznámka
                            <textarea
                                value={form.note ?? ""}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        note: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <div className="actions">
                            <button disabled={busy}>Uložit</button>
                            <button
                                type="button"
                                className="secondary"
                                onClick={() => setForm(null)}
                            >
                                Zrušit
                            </button>
                        </div>
                    </form>
                )}
                {selected && ["fueling", "expense"].includes(mode) && (
                    <section className="history">
                        <h2>
                            {mode === "fueling"
                                ? "Historie tankování"
                                : "Historie výdajů"}
                        </h2>
                        {!events.length ? (
                            <p>
                                Zatím zde nejsou žádné záznamy. Přidejte první.
                            </p>
                        ) : (
                            events.map((e) => (
                                <article className="entry" key={e.id}>
                                    <div>
                                        <strong>
                                            {e.occurred_on
                                                .split("-")
                                                .reverse()
                                                .join(". ")}
                                        </strong>
                                        <small>
                                            {e.odometer
                                                ? `${money(e.odometer)} ${selected.distance_unit}`
                                                : "Tachometr neuveden"}
                                        </small>
                                        {e.import_batch_id && (
                                            <small>
                                                Import #{e.import_batch_id}
                                            </small>
                                        )}
                                        <p>{e.note || "Bez poznámky"}</p>
                                    </div>
                                    <div>
                                        <strong>
                                            {money(e.amount)} {e.currency_code}
                                        </strong>
                                        <button
                                            className="secondary"
                                            disabled={
                                                !!selected.archived_at || busy
                                            }
                                            onClick={() =>
                                                run(async () => {
                                                    const d = await api(
                                                        `/vehicles/${vehicle}/events/${e.id}`,
                                                    );
                                                    setForm({
                                                        ...d.detail,
                                                        ...d.event,
                                                    });
                                                    setEditId(e.id);
                                                })
                                            }
                                        >
                                            Upravit
                                        </button>
                                    </div>
                                </article>
                            ))
                        )}
                        <div className="actions">
                            <button
                                className="secondary"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Předchozí
                            </button>
                            <span>
                                {page} / {lastPage}
                            </span>
                            <button
                                className="secondary"
                                disabled={page >= lastPage}
                                onClick={() => setPage(page + 1)}
                            >
                                Další
                            </button>
                        </div>
                    </section>
                )}
                <footer>
                    CCM · První verze evidence · Součty jsou oddělené podle měn.
                    CSV se zapisuje až potvrzením importu. Spotřeba se
                    vyhodnocuje pro každé médium zvlášť.
                </footer>
            </main>
        </>
    );
}
createRoot(document.getElementById("root")!).render(<App />);
