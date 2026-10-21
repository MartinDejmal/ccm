import { useState, FormEvent } from "react";
type Row = Record<string, any>;
type Props = {
    vehicles: Row[];
    catalogs: Row;
    api: (path: string, method?: string, data?: Row | FormData) => Promise<any>;
    changed: (vehicle: Row) => void;
    open: (vehicle: Row) => void;
    refresh: () => Promise<void>;
};
export function VehicleImage({
    vehicle,
    compact = false,
}: {
    vehicle: Row;
    compact?: boolean;
}) {
    return vehicle.avatar_url ? (
        <img
            className={compact ? "vehicle-avatar" : "garage-photo"}
            src={vehicle.avatar_url}
            alt={`Vozidlo ${vehicle.name}`}
            loading="lazy"
        />
    ) : (
        <div
            className={
                compact
                    ? "vehicle-avatar vehicle-placeholder"
                    : "garage-photo vehicle-placeholder"
            }
            aria-label="Vozidlo bez obrázku"
        >
            <svg viewBox="0 0 120 64" fill="none" aria-hidden="true">
                <path
                    d="m24 27 10-16h45l14 16 13 5v17H14V33l10-6Z"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinejoin="round"
                />
                <path
                    d="M28 27h60M56 13v14"
                    stroke="currentColor"
                    strokeWidth="3"
                />
                <circle cx="33" cy="49" r="8" fill="currentColor" />
                <circle cx="88" cy="49" r="8" fill="currentColor" />
            </svg>
        </div>
    );
}
export function Garage({
    vehicles,
    catalogs,
    api,
    changed,
    open,
    refresh,
}: Props) {
    const [filter, setFilter] = useState("active");
    const [editor, setEditor] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [archive, setArchive] = useState<Row | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0);
    async function run(action: () => Promise<void>) {
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    function edit(v: Row) {
        setEditor({ ...v });
        setFile(null);
        setFileKey(fileKey + 1);
        setArchive(null);
        setError("");
        setNotice("");
        requestAnimationFrame(() =>
            document
                .getElementById("garage-editor")
                ?.scrollIntoView({ behavior: "smooth", block: "start" }),
        );
    }
    function accept(v: Row) {
        changed(v);
        setEditor(v);
    }
    function acceptAvatar(v: Row) {
        changed(v);
        setEditor((current) => ({
            ...current,
            revision: v.revision,
            avatar_url: v.avatar_url,
        }));
    }
    async function save(e: FormEvent) {
        e.preventDefault();
        if (!editor) return;
        await run(async () => {
            const data: Row = {};
            for (const key of [
                "name",
                "make",
                "model",
                "vin",
                "registration_plate",
                "acquired_on",
                "initial_odometer",
                "distance_unit",
                "currency_code",
                "fuel_sort_id",
                "revision",
            ]) {
                if (editor[key] !== undefined)
                    data[key] = editor[key] === "" ? null : editor[key];
            }
            const v = await api(
                editor.id ? `/vehicles/${editor.id}` : "/vehicles",
                editor.id ? "PUT" : "POST",
                data,
            );
            accept(v);
            setNotice(
                "Údaje vozidla byly uloženy. Obrázek můžete nahrát níže.",
            );
        });
    }
    function field(
        label: string,
        name: string,
        type = "text",
        required = false,
        maxLength?: number,
    ) {
        return (
            <label>
                {label}
                <input
                    name={name}
                    type={type}
                    required={required}
                    maxLength={maxLength}
                    step={type === "number" ? "0.001" : undefined}
                    min={type === "number" ? "0" : undefined}
                    value={editor?.[name] ?? ""}
                    onChange={(e) =>
                        setEditor({ ...editor, [name]: e.target.value })
                    }
                />
            </label>
        );
    }
    const visible = vehicles.filter(
        (v) =>
            filter === "all" ||
            (filter === "archived" ? !!v.archived_at : !v.archived_at),
    );
    return (
        <section className="garage" aria-label="Moje garáž">
            <div className="heading">
                <p>Vaše vozidla, jejich údaje a obrázky.</p>
                <button
                    disabled={busy}
                    onClick={() =>
                        edit({
                            name: "",
                            distance_unit: "km",
                            currency_code: "CZK",
                        })
                    }
                >
                    + Přidat vozidlo
                </button>
            </div>
            <div className="actions garage-tools">
                <label>
                    Zobrazit vozidla
                    <select
                        value={filter}
                        disabled={busy}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="active">
                            Aktivní (
                            {vehicles.filter((v) => !v.archived_at).length})
                        </option>
                        <option value="archived">
                            Archivovaná (
                            {vehicles.filter((v) => v.archived_at).length})
                        </option>
                        <option value="all">Všechna ({vehicles.length})</option>
                    </select>
                </label>
                <button
                    disabled={busy}
                    className="secondary"
                    onClick={() =>
                        run(async () => {
                            await refresh();
                            setEditor(null);
                            setArchive(null);
                        })
                    }
                >
                    Načíst aktuální údaje
                </button>
            </div>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {notice && (
                <p role="status" className="success">
                    {notice}
                </p>
            )}
            {archive && (
                <section className="notice" aria-label="Potvrzení archivace">
                    <strong>Archivovat vozidlo {archive.name}?</strong>
                    <p>
                        Historie a obrázek zůstanou zachované. Nové záznamy a
                        úpravy historie budou možné po obnovení vozidla.
                    </p>
                    <div className="actions">
                        <button
                            disabled={busy}
                            onClick={() =>
                                run(async () => {
                                    const v = await api(
                                        `/vehicles/${archive.id}/archive`,
                                        "PATCH",
                                        {
                                            revision: archive.revision,
                                            archived: true,
                                        },
                                    );
                                    changed(v);
                                    if (editor?.id === v.id) setEditor(v);
                                    setArchive(null);
                                    setNotice(
                                        "Vozidlo bylo archivováno. Najdete ho mezi archivovanými vozidly.",
                                    );
                                })
                            }
                        >
                            Potvrdit archivaci
                        </button>
                        <button
                            disabled={busy}
                            className="secondary"
                            onClick={() => setArchive(null)}
                        >
                            Zrušit
                        </button>
                    </div>
                </section>
            )}
            {editor && (
                <section
                    id="garage-editor"
                    className="garage-editor"
                    aria-label="Úprava vozidla"
                >
                    <form onSubmit={save}>
                        <h2>
                            {editor.id
                                ? `Upravit vozidlo: ${editor.name}`
                                : "Nové vozidlo"}
                        </h2>
                        <fieldset disabled={busy}>
                            <div className="fields">
                                {field(
                                    "Název vozidla",
                                    "name",
                                    "text",
                                    true,
                                    160,
                                )}
                                {field("Značka", "make", "text", false, 100)}
                                {field("Model", "model", "text", false, 100)}
                                {field(
                                    "Registrační značka",
                                    "registration_plate",
                                    "text",
                                    false,
                                    32,
                                )}
                                {field("VIN", "vin", "text", false, 32)}
                                {field("Datum pořízení", "acquired_on", "date")}
                                {field(
                                    "Počáteční tachometr",
                                    "initial_odometer",
                                    "number",
                                )}
                                <label>
                                    Jednotka vzdálenosti
                                    <select
                                        required
                                        value={editor.distance_unit}
                                        onChange={(e) =>
                                            setEditor({
                                                ...editor,
                                                distance_unit: e.target.value,
                                            })
                                        }
                                    >
                                        <option value="km">
                                            Kilometry (km)
                                        </option>
                                        <option value="mi">Míle (mi)</option>
                                    </select>
                                </label>
                                <label>
                                    Výchozí měna
                                    <select
                                        required
                                        value={editor.currency_code}
                                        onChange={(e) =>
                                            setEditor({
                                                ...editor,
                                                currency_code: e.target.value,
                                            })
                                        }
                                    >
                                        {(catalogs.currencies ?? []).map(
                                            (c: Row) => (
                                                <option
                                                    key={c.code}
                                                    value={c.code}
                                                >
                                                    {c.code}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                {!editor.id && (
                                    <label>
                                        Výchozí palivo / energie
                                        <select
                                            required
                                            value={editor.fuel_sort_id ?? ""}
                                            onChange={(e) =>
                                                setEditor({
                                                    ...editor,
                                                    fuel_sort_id:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Vyberte…</option>
                                            {(catalogs.fuel_sorts ?? []).map(
                                                (f: Row) => (
                                                    <option
                                                        key={f.id}
                                                        value={f.id}
                                                    >
                                                        {f.name}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                )}
                            </div>
                            <p className="help">
                                Výchozí měna a počáteční tachometr nemění
                                existující záznamy. Jednotku vzdálenosti lze
                                změnit pouze před založením historie či CSV
                                náhledu.
                            </p>
                            <div className="actions">
                                <button>Uložit údaje</button>
                                <button
                                    className="secondary"
                                    type="button"
                                    onClick={() => setEditor(null)}
                                >
                                    Zavřít
                                </button>
                            </div>
                        </fieldset>
                    </form>
                    {editor.id ? (
                        <form
                            className="avatar-editor"
                            onSubmit={(e) => {
                                e.preventDefault();
                                run(async () => {
                                    if (!file)
                                        throw Error("Vyberte obrázek vozidla.");
                                    const data = new FormData();
                                    data.append("avatar", file);
                                    data.append(
                                        "revision",
                                        String(editor.revision),
                                    );
                                    const v = await api(
                                        `/vehicles/${editor.id}/avatar`,
                                        "POST",
                                        data,
                                    );
                                    acceptAvatar(v);
                                    setFile(null);
                                    setFileKey(fileKey + 1);
                                    setNotice("Obrázek vozidla byl uložen.");
                                });
                            }}
                        >
                            <h3>Obrázek / avatar auta</h3>
                            <VehicleImage vehicle={editor} compact />
                            <fieldset disabled={busy}>
                                <label>
                                    Nahrát obrázek
                                    <input
                                        key={fileKey}
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(e) => {
                                            const f =
                                                e.target.files?.[0] ?? null;
                                            setFile(f);
                                        }}
                                    />
                                </label>
                                <p className="help">
                                    JPEG, PNG nebo WebP, nejvýše 5 MiB a rozměry
                                    32–4096 px. Obrázek se zmenší na nejvýše
                                    1280 px a odstraní se metadata.
                                </p>
                                <div className="actions">
                                    <button disabled={!file}>
                                        Uložit obrázek
                                    </button>
                                    <button
                                        disabled={!editor.avatar_url}
                                        className="secondary"
                                        type="button"
                                        onClick={() =>
                                            run(async () => {
                                                acceptAvatar(
                                                    await api(
                                                        `/vehicles/${editor.id}/avatar`,
                                                        "DELETE",
                                                        {
                                                            revision:
                                                                editor.revision,
                                                        },
                                                    ),
                                                );
                                                setNotice(
                                                    "Obrázek byl odstraněn.",
                                                );
                                            })
                                        }
                                    >
                                        Odstranit obrázek
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    ) : (
                        <p className="help">
                            Nejprve uložte vozidlo. Poté můžete nahrát jeho
                            obrázek.
                        </p>
                    )}
                </section>
            )}
            {!visible.length && (
                <p className="empty">
                    {vehicles.length
                        ? "Zvolenému filtru neodpovídají žádná vozidla."
                        : "Garáž je zatím prázdná. Přidejte své první vozidlo."}
                </p>
            )}
            <div className="garage-grid">
                {visible.map((v) => (
                    <article className="garage-card" key={v.id}>
                        <VehicleImage vehicle={v} />
                        <div className="garage-card-body">
                            <small>
                                {v.archived_at ? "ARCHIVOVANÉ" : "AKTIVNÍ"}
                            </small>
                            <h2>{v.name}</h2>
                            <p>
                                {[v.make, v.model].filter(Boolean).join(" ") ||
                                    "Značka a model neuvedeny"}
                            </p>
                            <p>
                                {v.registration_plate ||
                                    "Bez registrační značky"}{" "}
                                · {v.distance_unit} · {v.currency_code}
                            </p>
                            <div className="actions">
                                <button disabled={busy} onClick={() => open(v)}>
                                    Otevřít historii
                                </button>
                                <button
                                    disabled={busy}
                                    className="secondary"
                                    onClick={() => edit(v)}
                                >
                                    Upravit vozidlo
                                </button>
                                {v.archived_at ? (
                                    <button
                                        disabled={busy}
                                        className="secondary"
                                        onClick={() =>
                                            run(async () => {
                                                const updated = await api(
                                                    `/vehicles/${v.id}/archive`,
                                                    "PATCH",
                                                    {
                                                        revision: v.revision,
                                                        archived: false,
                                                    },
                                                );
                                                changed(updated);
                                                if (editor?.id === v.id)
                                                    setEditor(updated);
                                                setNotice(
                                                    "Vozidlo bylo obnoveno.",
                                                );
                                            })
                                        }
                                    >
                                        Obnovit vozidlo
                                    </button>
                                ) : (
                                    <button
                                        disabled={busy}
                                        className="secondary"
                                        onClick={() => {
                                            setArchive(v);
                                            setError("");
                                        }}
                                    >
                                        Archivovat
                                    </button>
                                )}
                            </div>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
