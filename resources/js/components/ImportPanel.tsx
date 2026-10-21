import { useEffect, useRef, useState, FormEvent } from "react";
type Row = Record<string, any>;
type Props = {
    vehicle: Row;
    streams: Row[];
    api: (path: string, method?: string, data?: Row | FormData) => Promise<any>;
};
export function ImportPanel({ vehicle, streams, api }: Props) {
    const version = useRef(0);
    const [kind, setKind] = useState("fueling"),
        [profile, setProfile] = useState("spritmonitor"),
        [separator, setSeparator] = useState(","),
        [stream, setStream] = useState("");
    const [file, setFile] = useState<File | null>(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [batches, setBatches] = useState<Row[]>([]),
        [batch, setBatch] = useState<Row | null>(null),
        [rows, setRows] = useState<Row[]>([]),
        [page, setPage] = useState(1),
        [last, setLast] = useState(1),
        [status, setStatus] = useState(""),
        [batchPage, setBatchPage] = useState(1),
        [batchLast, setBatchLast] = useState(1);
    const base = `/vehicles/${vehicle.id}/imports`;
    const currentStream = streams.find((s) => String(s.id) === stream);
    async function run(action: () => Promise<void>) {
        setBusy(true);
        setError("");
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    async function list() {
        const d = await api(`${base}?page=${batchPage}`);
        setBatches(d.data);
        setBatchLast(d.last_page);
    }
    useEffect(() => {
        run(list);
    }, [vehicle.id, batchPage]);
    useEffect(() => {
        const n = ++version.current;
        if (!batch) {
            setRows([]);
            return;
        }
        api(
            `${base}/${batch.id}/rows?page=${page}${status ? `&status=${status}` : ""}`,
        )
            .then((d) => {
                if (n !== version.current) return;
                setRows(d.data);
                setLast(d.last_page);
            })
            .catch((e) => {
                if (n === version.current) setError(e.message);
            });
        return () => {
            version.current++;
        };
    }, [batch?.id, page, status, batch?.updated_at]);
    useEffect(() => {
        if (!stream && streams.length) setStream(String(streams[0].id));
    }, [streams]);
    async function upload(e: FormEvent) {
        e.preventDefault();
        await run(async () => {
            if (!file) throw Error("Vyberte CSV soubor.");
            const data = new FormData();
            data.append("file", file);
            data.append("kind", kind);
            data.append("profile", profile);
            data.append("distance_unit", vehicle.distance_unit);
            data.append("decimal_separator", separator);
            if (kind === "fueling") {
                data.append("energy_stream_id", stream);
                data.append(
                    "quantity_unit",
                    currentStream?.quantity_unit ?? "",
                );
            }
            const result = await api(base, "POST", data);
            setBatch(result);
            setStatus("");
            setPage(1);
            await list();
        });
    }
    async function skip(row: Row) {
        await run(async () => {
            const updated = await api(
                `${base}/${batch!.id}/rows/${row.id}`,
                "PATCH",
                { skip: row.status !== "skipped" },
            );
            setBatch(updated);
            const d = await api(
                `${base}/${batch!.id}/rows?page=${page}${status ? `&status=${status}` : ""}`,
            );
            setRows(d.data);
            setLast(d.last_page);
            if (page > d.last_page) setPage(d.last_page);
            await list();
        });
    }
    const labels: Row = {
        valid: "V pořádku",
        warning: "Varování",
        error: "Chyba",
        skipped: "Přeskočeno",
        ready: "Připravený náhled",
        needs_review: "Vyžaduje kontrolu",
        cancelled: "Zrušeno",
    };
    return (
        <section className="history import-panel">
            <span className="badge">CSV / SPRITMONITOR</span>
            <h2>Import: kontrola a náhled</h2>
            <p>
                Vyberte soubor pro vozidlo <strong>{vehicle.name}</strong>.
                Náhled nic nezapisuje do tankování ani výdajů. Potvrzení importu
                bude následovat v dalším kroku.
            </p>
            {error && (
                <p className="error" role="alert">
                    {error}
                </p>
            )}
            <form onSubmit={upload}>
                <div className="fields">
                    <label>
                        Obsah souboru
                        <select
                            value={kind}
                            onChange={(e) => setKind(e.target.value)}
                        >
                            <option value="fueling">
                                Tankování / nabíjení
                            </option>
                            <option value="expense">Ostatní výdaje</option>
                        </select>
                    </label>
                    <label>
                        Zdroj / profil
                        <select
                            value={profile}
                            onChange={(e) => setProfile(e.target.value)}
                        >
                            <option value="spritmonitor">
                                SpritMonitor – uvozovky s lomítkem
                            </option>
                            <option value="spritmonitor_rfc">
                                SpritMonitor – zdvojené uvozovky
                            </option>
                            <option value="ccm">
                                CCM – zachovat nulové BC údaje
                            </option>
                        </select>
                    </label>
                    <label>
                        Desetinný oddělovač
                        <select
                            value={separator}
                            onChange={(e) => setSeparator(e.target.value)}
                        >
                            <option value=",">Čárka (63,64)</option>
                            <option value=".">Tečka (63.64)</option>
                        </select>
                    </label>
                    <label>
                        Jednotka vzdálenosti
                        <input value={vehicle.distance_unit} readOnly />
                        <small>
                            Potvrďte, že soubor používá stejnou jednotku jako
                            vozidlo.
                        </small>
                    </label>
                    {kind === "fueling" && (
                        <>
                            <label>
                                Nádrž / médium pro soubor
                                <select
                                    value={stream}
                                    required
                                    onChange={(e) => setStream(e.target.value)}
                                >
                                    <option value="">Vyberte…</option>
                                    {streams
                                        .filter((s) => s.is_active)
                                        .map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name} ({s.quantity_unit})
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <p>
                                Množství v souboru:{" "}
                                <strong>
                                    {currentStream?.quantity_unit ??
                                        "vyberte médium"}
                                </strong>
                                . Pro jiné médium použijte samostatný soubor.
                            </p>
                        </>
                    )}
                    <label>
                        CSV soubor (UTF-8, nejvýše 2 MiB / 5 000 záznamů)
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            required
                            onChange={(e) =>
                                setFile(e.target.files?.[0] ?? null)
                            }
                        />
                    </label>
                    <label className="check">
                        <input type="checkbox" required />
                        Potvrzuji jednotky a zvolené vozidlo.
                    </label>
                </div>
                <button disabled={busy}>Zkontrolovat soubor</button>
            </form>
            <h3>Uložené náhledy</h3>
            {!batches.length ? (
                <p>Zatím žádný náhled.</p>
            ) : (
                <div className="batch-list">
                    {batches.map((b) => (
                        <button
                            disabled={busy}
                            key={b.id}
                            className="secondary"
                            onClick={() =>
                                run(async () => {
                                    setBatch(await api(`${base}/${b.id}`));
                                    setStatus("");
                                    setPage(1);
                                })
                            }
                        >
                            #{b.id} · {b.original_filename} · {labels[b.status]}
                        </button>
                    ))}
                </div>
            )}
            <div className="actions">
                <button
                    className="secondary"
                    disabled={busy || batchPage <= 1}
                    onClick={() => setBatchPage(batchPage - 1)}
                >
                    Náhledy: předchozí stránka
                </button>
                <span>
                    {batchPage} / {batchLast}
                </span>
                <button
                    className="secondary"
                    disabled={busy || batchPage >= batchLast}
                    onClick={() => setBatchPage(batchPage + 1)}
                >
                    Další stránka náhledů
                </button>
            </div>
            {batch && (
                <div className="preview">
                    <h3>
                        #{batch.id} · {batch.original_filename}
                    </h3>
                    <p>
                        <strong>{labels[batch.status]}</strong> ·{" "}
                        {batch.summary.total_rows} záznamů ·{" "}
                        {batch.summary.error_rows} chybových ·{" "}
                        {batch.summary.warning_rows} s varováním ·{" "}
                        {batch.summary.skipped_rows} přeskočeno
                    </p>
                    {(batch.summary.warnings ?? []).map(
                        (w: string, i: number) => (
                            <p className="notice" key={i}>
                                {w}
                            </p>
                        ),
                    )}
                    <p>
                        Součty záznamů bez chyb, které nejsou přeskočené:{" "}
                        {Object.entries(batch.summary.amounts_by_currency)
                            .map(([c, v]) => `${v} ${c}`)
                            .join(" / ") || "žádné"}
                        . Tyto částky nejsou součástí nákladů vozidla.
                    </p>
                    <div className="actions">
                        <label>
                            Zobrazit záznamy
                            <select
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Všechny</option>
                                {["valid", "warning", "error", "skipped"].map(
                                    (s) => (
                                        <option key={s} value={s}>
                                            {labels[s]}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <button
                            className="secondary"
                            disabled={busy || batch.status === "cancelled"}
                            onClick={() =>
                                run(async () => {
                                    await api(`${base}/${batch.id}`, "DELETE");
                                    setBatch(await api(`${base}/${batch.id}`));
                                    await list();
                                })
                            }
                        >
                            Zrušit náhled
                        </button>
                    </div>
                    {rows.map((row) => (
                        <article
                            className={`preview-row ${row.status}`}
                            key={row.id}
                        >
                            <div className="heading">
                                <strong>
                                    Záznam {row.record_number} · řádek{" "}
                                    {row.physical_line_start} ·{" "}
                                    {labels[row.status]}
                                </strong>
                                <button
                                    disabled={
                                        busy || batch.status === "cancelled"
                                    }
                                    className="secondary"
                                    onClick={() => skip(row)}
                                >
                                    {row.status === "skipped"
                                        ? "Zahrnout zpět"
                                        : "Přeskočit"}
                                </button>
                            </div>
                            <p>
                                {row.normalized.event.occurred_on ??
                                    "Neplatné datum"}{" "}
                                ·{" "}
                                {row.normalized.event.amount ??
                                    "Neplatná částka"}{" "}
                                {row.normalized.event.currency_code ?? ""}
                            </p>
                            {row.issues.map((issue: Row, i: number) => (
                                <p
                                    key={i}
                                    className={
                                        issue.severity === "error"
                                            ? "error"
                                            : "notice"
                                    }
                                >
                                    <strong>{issue.field}:</strong>{" "}
                                    {issue.message}
                                </p>
                            ))}
                            <details>
                                <summary>Původní záznam a interpretace</summary>
                                <pre>{row.raw_record}</pre>
                                <pre>
                                    {JSON.stringify(row.normalized, null, 2)}
                                </pre>
                            </details>
                        </article>
                    ))}
                    {!rows.length && (
                        <p>Tomuto filtru neodpovídá žádný záznam.</p>
                    )}
                    <div className="actions">
                        <button
                            className="secondary"
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Předchozí záznamy
                        </button>
                        <span>
                            {page} / {last}
                        </span>
                        <button
                            className="secondary"
                            disabled={page >= last}
                            onClick={() => setPage(page + 1)}
                        >
                            Další záznamy
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
