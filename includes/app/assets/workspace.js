/* Shared-service workspace. Text from records/configuration is never inserted as HTML. */
(() => {
  "use strict";
  const config = window.vfWorkspace;
  if (!config) return;
  const $ = (selector) => document.querySelector(selector);
  const el = (tag, attributes = {}, children = []) => {
    const node = document.createElement(tag);
    Object.entries(attributes).forEach(([key, value]) => {
      if (key === "text") node.textContent = value;
      else if (key.startsWith("on")) node.addEventListener(key.slice(2), value);
      else if (key === "checked" || key === "disabled" || key === "selected")
        node[key] = value;
      else node.setAttribute(key, value);
    });
    children
      .flat()
      .filter(Boolean)
      .forEach((child) =>
        node.append(
          child.nodeType ? child : document.createTextNode(String(child)),
        ),
      );
    return node;
  };
  const button = (text, action, primary = false) =>
    el("button", {
      type: "button",
      class: `vf-button${primary ? " vf-primary" : ""}`,
      text,
      onclick: action,
    });
  const toolbarViewport = window.matchMedia?.("(max-width: 600px)");
  toolbarViewport?.addEventListener("change", ({ matches }) => {
    const more = $("#vf-content .vf-toolbar-more");
    if (!more) return;
    const focused = more.contains(document.activeElement);
    more.open = !matches;
    if (focused) (matches ? more.querySelector("summary") : $("#vf-content input[type=search]")).focus();
  });
  const state = {
    objects: [],
    object: null,
    query: { page: 1, per_page: 20 },
    rows: [],
    columns: [],
    availableColumns: [],
    stickyHeader: true,
    views: [],
    selected: new Set(),
    generation: 0,
  };
  let refreshGeneration = 0;
  let navigationGeneration = 0;
  let refreshing = false;
  let tableGeneration = 0;
  const abandoned = Symbol("abandoned workspace operation");
  function captureNavigation() {
    const refresh = refreshGeneration, navigation = navigationGeneration, object = state.object;
    return () => !!object && !refreshing && refresh === refreshGeneration && navigation === navigationGeneration && object === state.object;
  }
  function captureTable() {
    const current = captureNavigation(), generation = tableGeneration;
    return () => current() && generation === tableGeneration;
  }
  function beginTable(current = captureNavigation()) {
    if (!current()) return current;
    const generation = ++tableGeneration;
    $("#vf-content").setAttribute("aria-busy", "false");
    return () => current() && generation === tableGeneration;
  }
  function owned(current) {
    const owner = current, independent = dialogSession?.independent;
    const refresh = refreshGeneration, navigation = navigationGeneration, intent = dialogGeneration;
    current = () => owner() && intent === dialogGeneration && (independent || (refresh === refreshGeneration && navigation === navigationGeneration));
    const table = captureTable();
    const check = () => { if (!current()) throw abandoned; };
    check();
    const wait = async (promise, valid = current) => {
      const check = () => { if (!valid()) throw abandoned; };
      try { const value = await promise; check(); return value; }
      catch (error) { check(); throw error; }
    };
    return {
      current, wait,
      openDialog: (...args) => { check(); openDialog(...args); },
      request: (operation, data) => { check(); return wait(request(operation, data)); },
      load: () => wait(load(() => current() && table()), () => current() && table()),
    };
  }
  const navigationAction = (action, current = captureNavigation()) => safe((...args) => {
    if (current()) return action(...args);
  });
  const dialogAction = action => safe(event => {
    const current = fieldOwner(event.currentTarget);
    if (!current() || action.busy) throw abandoned;
    pendingDialog = null; ++dialogGeneration;
    return action({ ...owned(current), control: event.currentTarget }, event);
  });
  // A toolbar lookup reserves replacement intent without abandoning the visible draft.
  const dialogIntent = action => navigationAction(async event => {
    if (action.busy) throw abandoned;
    const current = beginDialog();
    try { return await action({ ...owned(current), control: event.currentTarget }); }
    catch (error) { if (current()) pendingDialog = null; throw error; }
  });
  function mutation(write, complete, table = false) {
    let committed = false, result, running = false;
    const recover = async function(initial) {
      if (running || !initial.current()) throw abandoned;
      running = true;
      const operation = table ? { ...owned(beginTable(initial.current)), control: initial.control } : initial;
      const control = operation.control, title = control?.textContent;
      try {
        if (control) control.disabled = true;
        if (!committed) { result = await write(operation); committed = true; }
        if (!operation.current()) throw abandoned;
        if (control) {
          control.disabled = true;
          control.textContent = control === $("#vf-dialog-submit") ? "Retry refresh" : "Retry";
          if (control === $("#vf-dialog-submit")) $("#vf-dialog-body").querySelectorAll("input,select,textarea,button").forEach(node => { node.disabled = true; });
        }
        if (await complete(operation, result) === false) throw abandoned;
        committed = false; result = undefined;
        if (!control && operation.current()) $("#vf-feedback").replaceChildren();
        if (control && operation.current()) control.textContent = title;
      } catch (error) {
        if (error === abandoned || !committed || control) throw error;
        notify(error.message, true);
        $("#vf-feedback").append(button("Retry refresh", safe(() => committed && !running && recover(initial))));
      } finally {
        running = false;
        if (control && operation.current()) control.disabled = false;
      }
    };
    Object.defineProperty(recover, "busy", { get: () => running });
    return recover;
  }
  const notify = (message, error = false) =>
    $("#vf-feedback").replaceChildren(
      el("div", {
        class: `vf-alert${error ? " vf-error" : ""}`,
        text: message,
      }),
    );
  const request = async (operation, data = {}) => {
    const body = new URLSearchParams({
      action: "acf_vf_workspace",
      nonce: config.nonce,
      operation,
      data: JSON.stringify(data),
    });
    const response = await fetch(config.url, {
      method: "POST",
      credentials: "same-origin",
      body,
    });
    const result = await response.json();
    if (!result.success) {
      const errors = result.data?.data?.errors || [];
      const seen = new Set();
      const details = errors
        .filter((item) => {
          const key = item.field || item.message;
          if (seen.has(key)) return false;
          seen.add(key);
          return true;
        })
        .map(
          (item) =>
            `${state.object?.fields[item.field]?.label || item.field || "Record"}: ${item.message}`,
        )
        .join("; ");
      throw new Error(
        details ||
          result.data?.message ||
          "The request failed. Please try again.",
      );
    }
    return result.data;
  };
  const safe =
    (action) =>
    async (...args) => {
      try {
        await action(...args);
      } catch (error) {
        if (error !== abandoned) notify(error.message, true);
      }
    };
  const label = (name, control, help = "") =>
    el("label", { class: "vf-field" }, [
      el("span", { text: name }),
      control,
      help ? el("small", { text: help }) : null,
    ]);
  const input = (value = "", type = "text", attrs = {}) =>
    el("input", { type, value: value ?? "", ...attrs });
  const select = (choices, value = "") =>
    el(
      "select",
      {},
      Object.entries(
        value !== "" && !Object.hasOwn(choices, String(value))
          ? { ...choices, [value]: String(value) }
          : choices,
      ).map(([key, title]) =>
        el("option", {
          value: key,
          text: title,
          selected: String(value) === key,
        }),
      ),
    );
  const textValue = (value) =>
    value == null
      ? ""
      : typeof value === "object"
        ? JSON.stringify(value)
        : String(value);
  let dialogSubmit = null;
  let returnFocus = null;
  let initialized = false;
  let dialogGeneration = 0;
  let dialogSession = null;
  let pendingDialog = null;
  const fieldOwners = new WeakMap();
  function fieldOwner(node) {
    const initial = captureDialog();
    return () => (fieldOwners.get(node) || initial)();
  }
  function captureDialogIntent() {
    const generation = dialogGeneration;
    return () => generation === dialogGeneration;
  }
  function captureDialog() {
    const generation = dialogGeneration, session = dialogSession;
    const refresh = refreshGeneration, navigation = navigationGeneration;
    return () => session
      ? session === dialogSession && (session.independent || (session.refresh === refreshGeneration && session.navigation === navigationGeneration))
      : generation === dialogGeneration && refresh === refreshGeneration && navigation === navigationGeneration;
  }
  function beginDialog(independent = false) {
    const generation = ++dialogGeneration;
    const session = pendingDialog = { independent, refresh: refreshGeneration, navigation: navigationGeneration };
    return () => pendingDialog === session && generation === dialogGeneration && (independent || (session.refresh === refreshGeneration && session.navigation === navigationGeneration));
  }
  function rebindDialog(session) {
    if (session && session === dialogSession) {
      session.refresh = refreshGeneration;
      session.navigation = navigationGeneration;
    }
  }
  function clearDialog() {
    closeDialog();
    $("#vf-dialog-title").textContent = "";
    $("#vf-dialog-body").replaceChildren();
    $("#vf-dialog-submit").hidden = true;
  }
  function clearObsoleteDialog() {
    if (pendingDialog && !pendingDialog.independent && (pendingDialog.refresh !== refreshGeneration || pendingDialog.navigation !== navigationGeneration)) pendingDialog = null;
    if (dialogSession && !dialogSession.independent && !captureDialog()()) {
      if (pendingDialog?.independent) {
        dialogSession = null; dialogSubmit = null;
        $("#vf-dialog").close(); $("#vf-dialog-body").replaceChildren();
      } else clearDialog();
    }
  }
  async function refreshDialog(message) {
    const session = dialogSession, current = captureDialogIntent();
    $("#vf-dialog-submit").textContent = "Retry refresh";
    $("#vf-dialog-body").querySelectorAll("input,select,textarea,button").forEach(node => { node.disabled = true; });
    return init(() => { if (current()) notify(message); }, session);
  }
  async function dialogRequest(operation, data, ownsDialog) {
    try { return await request(operation, data); }
    catch (error) { if (ownsDialog()) { pendingDialog = null; throw error; } }
  }
  function openDialog(title, body, submit, saveLabel = "Save", independent = false) {
    beginDialog(independent);
    dialogSession = pendingDialog; pendingDialog = null;
    const generation = dialogGeneration;
    returnFocus = document.activeElement;
    $("#vf-dialog-title").textContent = title;
    $("#vf-dialog-body").replaceChildren(body);
    const current = captureDialog();
    [body, ...body.querySelectorAll("*")].forEach(node => fieldOwners.set(node, current));
    $("#vf-dialog-submit").textContent = saveLabel;
    $("#vf-dialog-submit").hidden = !submit;
    $("#vf-dialog-submit").disabled = false;
    dialogSubmit = submit ? () => {
      if (!current() || submit.busy) throw abandoned;
      pendingDialog = null; ++dialogGeneration;
      return submit({ ...owned(current), control: $("#vf-dialog-submit") });
    } : null;
    $("#vf-dialog").showModal();
    setTimeout(
      () => generation === dialogGeneration &&
        $("#vf-dialog-body")
          .querySelector("input,select,textarea,button")
          ?.focus(),
      0,
    );
  }
  function closeDialog() {
    ++dialogGeneration;
    dialogSession = null; pendingDialog = null;
    dialogSubmit = null;
    $("#vf-dialog").close();
    returnFocus?.focus();
  }
  $("#vf-dialog").addEventListener("cancel", event => {
    event.preventDefault();
    closeDialog();
  });
  $("#vf-dialog")
    .querySelectorAll("[data-close]")
    .forEach((node) => node.addEventListener("click", closeDialog));
  $("#vf-dialog-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!dialogSubmit) return;
    const session = dialogSession;
    let generation;
    const submit = $("#vf-dialog-submit");
    submit.disabled = true;
    $("#vf-dialog-body").querySelector(".vf-dialog-error")?.remove();
    try {
      const completion = dialogSubmit();
      generation = dialogGeneration;
      await completion;
      if (generation === dialogGeneration && session === dialogSession) closeDialog();
    } catch (error) {
      if (error === abandoned || generation !== dialogGeneration) return;
      $("#vf-dialog-body").prepend(
        el("div", {
          class: "vf-alert vf-error vf-dialog-error",
          role: "alert",
          text: error.message,
        }),
      );
    } finally {
      if (generation !== undefined && session === dialogSession) submit.disabled = false;
    }
  });
  async function init(onApplied = null, sourceDialog = null) {
    const generation = ++refreshGeneration;
    const dialogIntent = dialogGeneration;
    ++navigationGeneration;
    ++state.generation;
    rebindDialog(sourceDialog);
    refreshing = true;
    let objects;
    try { objects = await request("objects"); }
    catch (error) {
      if (generation !== refreshGeneration) return false;
      refreshing = false;
      $("#vf-content").setAttribute("aria-busy", "false");
      throw error;
    }
    if (generation !== refreshGeneration) return false;
    refreshing = false;
    state.objects = objects;
    clearObsoleteDialog();
    const nav = $("#vf-objects");
    nav.replaceChildren(
      ...state.objects.map((object) =>
        button(
          object.label,
          safe(() => choose(object.slug)),
        ),
      ),
    );
    nav
      .querySelectorAll("button")
      .forEach(
        (node, index) => (node.dataset.object = state.objects[index].slug),
      );
    if (config.configure)
      nav.append(
        button("Background jobs", safe(() => jobsDialog())),
        button("Field indexes", safe(indexDialog)),
        button("Configuration transfer", safe(() => premium.configuration ? premium.configuration() : configurationExport())),
      );
    $("#vf-create-object").hidden = !config.configure;
    if (state.objects.length) {
      const params = new URLSearchParams(location.search);
      const candidates = initialized
        ? [state.object?.slug, params.get("object")]
        : [params.get("object"), state.object?.slug];
      const startup = !initialized;
      initialized = true;
      const target = candidates.find(
        slug => state.objects.some(object => object.slug === slug),
      );
      let current = await choose(target || state.objects[0].slug, sourceDialog);
      if (!current) return true;
      if (startup && params.has("record") && dialogIntent === dialogGeneration) {
        let record;
        try { record = await request("record", { id: Number(params.get("record")) }); }
        catch (error) { if (!current() || dialogIntent !== dialogGeneration) return true; throw error; }
        if (!current() || dialogIntent !== dialogGeneration) return true;
        if (!state.objects.some(object => object.slug === record.object))
          throw new Error("The linked record is no longer available in this workspace.");
        if (record.object !== state.object.slug) current = await choose(record.object);
        if (!current || dialogIntent !== dialogGeneration) return true;
        const recordOwner = await recordDialog(record, {}, current);
        if (!recordOwner || !recordOwner()) return true;
        history.replaceState(
          null,
          "",
          location.pathname + "?page=validated-field-workspace",
        );
      }
    } else {
      initialized = true;
      state.object = null;
      state.query = { page: 1, per_page: 20 };
      state.rows = [];
      state.columns = [];
      state.availableColumns = [];
      state.views = [];
      state.view = "";
      state.selected.clear();
      $("#vf-title").textContent = "";
      $("#vf-subtitle").textContent = "";
      $("#vf-header-actions").replaceChildren();
      if (onApplied) onApplied();
      if ((!dialogSession?.independent && !pendingDialog?.independent) || (sourceDialog && dialogSession === sourceDialog)) clearDialog();
      $("#vf-content").setAttribute("aria-busy", "false");
      $("#vf-content").replaceChildren(
        el("div", { class: "vf-empty" }, [
          el("h2", { text: "Build your first object" }),
          el("p", {
            text: "An object holds a collection of records, such as Accounts, Contacts, or Projects.",
          }),
          config.configure
            ? button("Create an object", () => designer(), true)
            : el("p", {
                text: "Ask an administrator to configure objects for this workspace.",
              }),
        ]),
      );
    }
    if (state.object && onApplied) onApplied();
    return true;
  }
  async function choose(slug, sourceDialog = null) {
    const object = state.objects.find((object) => object.slug === slug);
    if (!object) return false;
    ++navigationGeneration;
    ++state.generation;
    rebindDialog(sourceDialog);
    clearObsoleteDialog();
    state.object = object;
    const current = beginTable();
    state.query = { page: 1, per_page: 20 };
    state.view = "";
    state.selected.clear();
    $("#vf-title").textContent = state.object.label;
    $("#vf-subtitle").textContent =
      `Manage ${state.object.label.toLowerCase()} and keep every change validated.`;
    $("#vf-objects")
      .querySelectorAll("button")
      .forEach((node) =>
        node.setAttribute("aria-current", String(node.dataset.object === slug)),
      );
    const actions = [];
    if (config.configure && !state.object.native_table)
      actions.push(
        button(
          "Configure",
          safe(() => designer(slug)),
        ),
      );
    if (state.object.can_create)
      actions.push(
        button(
          `+ New ${state.object.singular}`,
          safe(() => recordDialog()),
          true,
        ),
      );
    $("#vf-header-actions").replaceChildren(...actions);
    let results;
    try { results = await Promise.all([
      request("columns", { object: slug }),
      premium.views ? premium.views(slug) : Promise.resolve([]),
      request("table_preferences", { object: slug }),
    ]); }
    catch (error) { if (!current()) return false; throw error; }
    const [columns, views, preferences] = results;
    if (!current()) return false;
    state.availableColumns = columns;
    state.columns = preferences.columns || columns;
    state.stickyHeader = preferences.sticky_header !== false;
    state.views = views;
    await premium.applyDefault?.(current);
    if (!current()) return false;
    await load(current);
    return current() ? current : false;
  }
  async function load(current = captureTable()) {
    if (!current()) return false;
    const generation = ++state.generation;
    $("#vf-content").setAttribute("aria-busy", "true");
    try {
      const result = await request("query", {
        object: state.object.slug,
        query: state.query,
        columns: state.columns.map(({ field, label, width, formatter }) => ({ field, ...(label ? { label } : {}), ...(width ? { width } : {}), ...(formatter ? { formatter } : {}) })),
      });
      if (generation !== state.generation || !current()) return false;
      state.rows = result.items;
      state.selected.clear();
      renderList(result);
      return true;
    } catch (error) {
      if (generation === state.generation && current()) throw error;
    } finally {
      if (generation === state.generation && current()) $("#vf-content").setAttribute("aria-busy", "false");
    }
    return false;
  }
  function renderList(result) {
    const current = captureNavigation();
    const search = input(state.query.search || "", "search", {
      "aria-label": "Search records",
      placeholder: "Search records…",
    });
    let timer;
    search.addEventListener("input", () => {
      clearTimeout(timer);
      const intent = beginTable(current);
      timer = setTimeout(
        safe(async () => {
          if (!intent()) return;
          state.query.search = search.value;
          state.query.page = 1;
          await load(intent);
        }),
        350,
      );
    });
    const primary = el("div", { class: "vf-toolbar-primary" }, [
      button("Filters", navigationAction(filtersDialog)),
      button("Columns", navigationAction(columnsDialog)),
    ]);
    if (state.object.can_create) primary.append(button("Quick Add", navigationAction(quickAdd)));
    const toolbar = el("div", { class: "vf-toolbar" }, [search, primary]);
    const secondary = el("div", { class: "vf-toolbar-secondary" });
    premium.toolbar?.(secondary);
    if (state.object.can_export)
      secondary.append(
        button(
          "Export CSV",
          safe(async () => {
            const table = captureTable(), dialog = captureDialog();
            const { request } = owned(() => current() && table() && dialog());
            const file = await request("export_csv", {
              object: state.object.slug,
              query: state.query,
              columns: state.columns.map(({ field }) => ({ field })),
            });
            download(file);
            notify(`Exported ${file.total} records.`);
          }),
        ),
      );
    const savedView = secondary.querySelector('select[aria-label="Saved view"]');
    if (savedView) toolbar.insertBefore(savedView, primary);
    if (secondary.childElementCount) {
      const more = el("details", { class: "vf-toolbar-more" }, [
        el("summary", { class: "vf-button", text: "More actions", onblur: () => {
          // Native CSS hiding can blur the summary before the media change event.
          if (toolbarViewport && !toolbarViewport.matches) search.focus();
        } }), secondary,
      ]);
      more.open = !toolbarViewport?.matches;
      toolbar.append(more);
    }
    const all = input("", "checkbox", { "aria-label": "Select current page" });
    all.addEventListener("change", () => {
      state.selected.clear();
      if (all.checked) state.rows.forEach((row) => state.selected.add(row.id));
      $("#vf-content")
        .querySelectorAll("[data-row-select]")
        .forEach((node) => (node.checked = all.checked));
    });
    const primarySort = Array.isArray(state.query.sort) ? state.query.sort[0] : state.query.sort;
    const ascending = primarySort?.direction?.toLowerCase() === "asc";
    const head = el("tr", {}, [
      el("th", {}, [all]),
      ...state.columns.map((column) => {
        const title = column.sortable
          ? el("button", {
              class: "vf-sort",
              type: "button",
              text:
                column.label +
                (primarySort?.field === column.field
                  ? ascending
                    ? " ↑"
                    : " ↓"
                  : ""),
              onclick: safe(async () => {
                const intent = beginTable(current);
                if (!intent()) return;
                state.query.sort = {
                  field: column.field,
                  direction:
                    primarySort?.field === column.field && ascending
                      ? "desc"
                      : "asc",
                };
                await load(intent);
              }),
            })
          : el("span", { text: column.label });
        const header = el(
          "th",
          {
            scope: "col",
            "aria-sort": primarySort?.field === column.field ? (ascending ? "ascending" : "descending") : "none",
            "data-field": column.field,
            draggable: "true",
            ...(column.width ? { style: `width:${column.width}` } : {}),
          },
          [title],
        );
        columnHeader(header, column, current);
        return header;
      }),
      el("th", { scope: "col", text: "Actions" }),
    ]);
    const tbody = el(
      "tbody",
      {},
      state.rows.map((row) => {
        const check = input("", "checkbox", {
          "aria-label": `Select ${row.title || row.id}`,
          "data-row-select": row.id,
        });
        check.addEventListener("change", () =>
          check.checked
            ? state.selected.add(row.id)
            : state.selected.delete(row.id),
        );
        return el("tr", {}, [
          el("td", {}, [check]),
          ...state.columns.map((column) => {
            const value =
              column.field in row
                ? row[column.field]
                : row.fields[column.field];
            const display = row.display?.[column.field] ?? textValue(value);
            const cell = el("td", { title: display });
            if (column.field === "title")
              cell.append(
                el("button", {
                  type: "button",
                  class: "vf-cell",
                  text: textValue(value) || "(Untitled)",
                  onclick: navigationAction(() => recordDialog(row)),
                }),
              );
            else if (column.editable && (freeScalar(state.object.fields[column.field]) || premium.inline))
              cell.append(
                el("button", {
                  type: "button",
                  class: "vf-cell",
                  text: display || "—",
                  title: "Edit value",
                  onclick: navigationAction(() => freeScalar(state.object.fields[column.field]) ? scalarEdit(row, column) : premium.inline(row, column)),
                }),
              );
            else cell.textContent = display || "—";
            premium.formatCell?.(cell, row, column);
            return cell;
          }),
          el("td", {}, [
            button(
              "Open",
              navigationAction(() => recordDialog(row)),
            ),
          ]),
        ]);
      }),
    );
    if (!state.rows.length)
      tbody.append(
        el("tr", {}, [
          el("td", { colspan: state.columns.length + 2 }, [
            el("div", {
              class: "vf-empty",
              text: "No records match this view.",
            }),
          ]),
        ]),
      );
    const previous = button(
      "Previous",
      safe(async () => {
        const intent = beginTable(current);
        if (!intent()) return;
        state.query.page--;
        await load(intent);
      }),
    );
    previous.disabled = result.page <= 1;
    const next = button(
      "Next",
      safe(async () => {
        const intent = beginTable(current);
        if (!intent()) return;
        state.query.page++;
        await load(intent);
      }),
    );
    next.disabled = result.page >= result.total_pages;
    $("#vf-content").replaceChildren(
      toolbar,
      el("div", { class: "vf-table-wrap" }, [
        el("table", { class: `vf-table${state.stickyHeader ? " vf-sticky-header" : ""}` }, [el("thead", {}, [head]), tbody]),
      ]),
      el("div", { class: "vf-pagination" }, [
        el("span", { text: `${result.total} records` }),
        el("div", {}, [
          previous,
          el("span", {
            text: `Page ${result.page} of ${Math.max(1, result.total_pages)}`,
          }),
          next,
        ]),
      ]),
      ...(premium.metrics ? [premium.metrics(result)] : []),
    );
  }
  function freeScalar(definition) {
    if (!definition) return false;
    const field = ["validated_field", "validated"].includes(definition.type) ? { ...definition, ...definition.sub_field } : definition;
    return !definition.formula && !definition.rollup && !field.multiple && !field.relationship && ["text", "textarea", "number", "range", "email", "url", "password", "true_false", "date_picker", "date_time_picker", "time_picker", "color_picker"].includes(field.type);
  }
  function scalarEdit(row, column) {
    const definition = state.object.fields[column.field];
    const field = ["validated_field", "validated"].includes(definition.type) ? { ...definition, ...definition.sub_field } : definition;
    const control = fieldControl(field, row.fields[column.field]);
    openDialog(`Edit ${column.label}`, label(column.label, control.node), mutation(({ request }) => request("inline_update", { id: row.id, field: column.field, value: control.read(), version: row.version }), async ({ load }) => { await load(); notify("Value updated."); }));
  }
  function quickAdd() {
    const title = input("", "text", { "aria-label": "Title" });
    const body = el("div", {}, [label("Title", title)]);
    const controls = {};
    const changed = new Set();
    Object.entries(state.object.fields).forEach(([name, field]) => {
      if (!freeScalar(field) || field.editable === false || field.readonly || field.disabled) return;
      const native = ["validated_field", "validated"].includes(field.type) ? { ...field, ...field.sub_field } : field;
      const control = fieldControl(native, field.default_value ?? null);
      controls[name] = control;
      for (const type of ["input", "change"]) control.node.addEventListener(type, () => changed.add(name));
      body.append(label(field.label || name, control.node));
    });
    body.append(el("p", { class: "vf-help", text: "Every value is validated before saving. Use the full record form for media, choices and structured fields." }));
    openDialog("Quick Add", body, mutation(async ({ request }) => {
      const fields = Object.fromEntries([...changed].map(name => [name, controls[name].read()]));
      return request("quick_add", { object: state.object.slug, payload: { title: title.value, fields } });
    }, async ({ load }) => { await load(); notify("Record added."); }), "Add record");
  }
  const writePersonalColumns = (columns, sticky, { request }) => request("save_table_preferences", { object: state.object.slug, preferences: { columns: columns.map(({ field, width }) => ({ field, ...(width ? { width } : {}) })), sticky_header: sticky } });
  async function applyPersonalColumns({ load }, saved) {
    state.columns = saved.columns;
    state.stickyHeader = saved.sticky_header;
    await load();
  }
  async function savePersonalColumns(columns, sticky, current) {
    if (!current()) return;
    await mutation(operation => writePersonalColumns(columns, sticky, operation), applyPersonalColumns)(owned(current));
  }
  let draggedColumn = "";
  function columnHeader(header, column, current) {
    header.addEventListener("dragstart", event => { draggedColumn = column.field; event.dataTransfer?.setData("text/plain", column.field); });
    header.addEventListener("dragover", event => event.preventDefault());
    header.addEventListener("drop", safe(async event => {
      event.preventDefault();
      if (!current()) return;
      const from = state.columns.findIndex(item => item.field === draggedColumn);
      const to = state.columns.findIndex(item => item.field === column.field);
      draggedColumn = "";
      if (from < 0 || to < 0 || from === to) return;
      const columns = [...state.columns]; const [moved] = columns.splice(from, 1); columns.splice(to, 0, moved);
      await savePersonalColumns(columns, state.stickyHeader, beginTable(current));
    }));
    const initialWidth = () => Math.min(1200, Math.max(40, Math.round(header.getBoundingClientRect().width || parseInt(column.width, 10) || 160)));
    const handle = el("span", { class: "vf-column-resize", role: "separator", tabindex: 0, "aria-orientation": "vertical", "aria-label": `Resize ${column.label}`, "aria-valuemin": 40, "aria-valuemax": 1200, "aria-valuenow": initialWidth() });
    const resize = async (width, intent) => {
      width = Math.min(1200, Math.max(40, width));
      await savePersonalColumns(state.columns.map(item => item.field === column.field ? { ...item, width: `${width}px` } : item), state.stickyHeader, intent);
    };
    handle.addEventListener("keydown", safe(async event => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault(); event.stopPropagation();
      await resize(event.key === "Home" ? 40 : event.key === "End" ? 1200 : initialWidth() + (event.key === "ArrowRight" ? 10 : -10), beginTable(current));
    }));
    handle.addEventListener("pointerdown", event => {
      event.preventDefault(); event.stopPropagation();
      const intent = beginTable(current);
      const x = event.clientX, start = initialWidth(); let width = start;
      const move = e => { width = Math.min(1200, Math.max(40, start + e.clientX - x)); header.style.width = `${width}px`; handle.setAttribute("aria-valuenow", width); };
      const end = safe(async () => { document.removeEventListener("pointermove", move); document.removeEventListener("pointerup", end); document.removeEventListener("pointercancel", cancel); await resize(width, intent); });
      const cancel = () => { document.removeEventListener("pointermove", move); document.removeEventListener("pointerup", end); document.removeEventListener("pointercancel", cancel); header.style.width = column.width || ""; };
      document.addEventListener("pointermove", move); document.addEventListener("pointerup", end); document.addEventListener("pointercancel", cancel);
    });
    header.append(handle);
  }
  function fieldControl(field, value) {
    const type = field.type;
    if (field.relationship?.object) {
      const multiple = type === "relationship" || !!field.multiple;
      const selected = (Array.isArray(value) ? value : [value])
        .map(Number)
        .filter((id) => Number.isSafeInteger(id) && id > 0);
      const node = el("select");
      node.setAttribute("aria-label", (field.label || "Related records") + " choices");
      const search = input("", "search", {
        "aria-label": "Search " + (field.label || "related records"),
        placeholder: "Search records…",
        maxlength: 200,
      });
      const feedback = el("small", { class: "vf-help", role: "status", "aria-live": "polite" });
      const container = el("div", {}, [search, node, feedback]);
      if (multiple) {
        node.multiple = true;
        node.size = 5;
      }
      if (!multiple)
        node.append(el("option", { value: "", text: "Choose a record…", selected: !selected.length }));
      // Keep known IDs even while choices load or a selected record is unreadable.
      // Titles come only from permission-checked query/read responses.
      const ownsField = fieldOwner(container);
      let options = new Map();
      selected.forEach((id) => {
        const option = el("option", { value: id, text: "Selected record #" + id, selected: true });
        options.set(id, option);
        node.append(option);
      });
      let generation = 0;
      let timer;
      const lookedUp = new Set();
      const loadChoices = (term, current) => {
        if (current !== generation || !ownsField()) return;
        feedback.textContent = "Searching records…";
        request("query", {
          object: field.relationship.object,
          query: { per_page: 100, ...(term ? { search: term } : {}) },
        })
        .then(async (result) => {
          if (current !== generation || !ownsField()) return;
          // Take selection at response time, including edits made during the request.
          options = new Map([...node.selectedOptions]
            .filter((option) => option.value)
            .map((option) => [Number(option.value), option]));
          const returned = new Set();
          result.items.forEach((record) => {
            returned.add(record.id);
            if (options.has(record.id)) options.get(record.id).textContent = record.title || "#" + record.id;
            else {
              const option = el("option", { value: record.id, text: record.title || "#" + record.id, selected: false });
              options.set(record.id, option);
            }
          });
          const choices = [...options.values()];
          node.replaceChildren(
            ...(!multiple ? [el("option", { value: "", text: "Choose a record…", selected: !choices.some((option) => option.selected) })] : []),
            ...choices,
          );
          feedback.textContent = (result.total ?? result.items.length) > result.items.length
            ? `Showing the first ${result.items.length} matches. Refine your search for more records. Selected records are kept.`
            : `${result.items.length} matching records. Selected records are kept.`;
          await Promise.allSettled(selected.filter((id) => !returned.has(id) && !lookedUp.has(id)).map(async (id) => {
            lookedUp.add(id);
            const record = await request("record", { id });
            if (current === generation && ownsField() && record.id === id && record.object === field.relationship.object)
              if (options.has(id)) options.get(id).textContent = record.title || "#" + id;
          }));
        })
        .catch((error) => {
          if (current !== generation || !ownsField()) return;
          feedback.textContent = "Records could not load. Selected records are kept.";
          notify(error.message, true);
        });
      };
      search.addEventListener("input", (event) => {
        event.stopPropagation();
        clearTimeout(timer);
        const current = ++generation;
        timer = setTimeout(() => loadChoices(search.value, current), 350);
      });
      search.addEventListener("change", (event) => event.stopPropagation());
      loadChoices("", ++generation);
      return {
        node: container,
        read: () =>
          multiple
            ? [...node.selectedOptions].map((option) => Number(option.value))
            : node.value
              ? Number(node.value)
              : null,
      };
    }
    if (type === "file" || type === "image") {
      let attachment = Number(value || 0);
      const summary = el("span", {class:"vf-help", text:attachment ? "Selected attachment #" + attachment : "No file selected"});
      const node = el("div", {class:"vf-toolbar"}, [summary]);
      const choose = button("Choose " + (type === "image" ? "image" : "file"), () => {
        const current = fieldOwner(node);
        if (!current()) return;
        if (!window.wp?.media) { notify("The WordPress media library could not load.", true); return; }
        const frame = window.wp.media({title:"Choose " + field.label, multiple:false, ...(type === "image" ? {library:{type:"image"}} : {})});
        frame.on("select", () => {if (!current()) return; const selected = frame.state().get("selection").first().toJSON();attachment=selected.id;summary.textContent=selected.filename || selected.title;node.dispatchEvent(new Event("change",{bubbles:true}));});
        frame.on("close", () => setTimeout(() => {if (current() && !$("#vf-dialog").open) $("#vf-dialog").showModal();}, 0));
        $("#vf-dialog").close(); frame.open();
      });
      choose.setAttribute("aria-label", "Choose " + (type === "image" ? "image" : "file"));
      node.append(choose, button("Remove",()=>{attachment=0;summary.textContent="No file selected";node.dispatchEvent(new Event("change",{bubbles:true}));}));
      return {node,read:()=>attachment || null};
    }
    if (type === "true_false") {
      const node = input("1", "checkbox", { checked: !!value });
      return { node, read: () => (node.checked ? 1 : 0) };
    }
    if (
      ["select", "radio", "button_group"].includes(type) &&
      !field.multiple &&
      field.choices
    ) {
      const node = select({ "": "Choose…", ...field.choices }, value ?? "");
      return { node, read: () => node.value };
    }
    if (type === "group") {
      const node = el("fieldset", { class: "vf-designer-row" });
      const controls = {};
      (field.sub_fields || []).forEach((child) => {
        const control = fieldControl(child, value?.[child.name]);
        controls[child.name] = control;
        node.append(label(child.label || child.name, control.node));
      });
      return {
        node,
        read: () =>
          Object.fromEntries(
            Object.entries(controls).map(([name, control]) => [
              name,
              control.read(),
            ]),
          ),
      };
    }
    if (type === "repeater" || type === "flexible_content") {
      const node = el("div");
      const rows = el("div");
      const layouts =
        type === "flexible_content"
          ? field.layouts || []
          : [{ name: "", label: "Row", sub_fields: field.sub_fields || [] }];
      const add = (rowValue = {}, layoutName = "") => {
        const layout =
          layouts.find(
            (item) => item.name === (rowValue.acf_fc_layout || layoutName),
          ) || layouts[0];
        if (!layout) return;
        const row = el("fieldset", { class: "vf-designer-row" });
        const controls = {};
        row.append(el("legend", { text: layout.label || "Row" }));
        layout.sub_fields.forEach((child) => {
          const control = fieldControl(child, rowValue[child.name]);
          controls[child.name] = control;
          row.append(label(child.label || child.name, control.node));
        });
        row.append(button("Remove row", () => {
          row.remove();
          node.dispatchEvent(new Event("change", { bubbles: true }));
        }));
        row.read = () => ({
          ...Object.fromEntries(
            Object.entries(controls).map(([name, control]) => [
              name,
              control.read(),
            ]),
          ),
          ...(type === "flexible_content"
            ? { acf_fc_layout: layout.name }
            : {}),
        });
        rows.append(row);
      };
      (Array.isArray(value) ? value : []).forEach((row) => add(row));
      node.append(rows);
      layouts.forEach((layout) =>
        node.append(
          button("+ " + (layout.label || "Add row"), () => {
            add({}, layout.name);
            node.dispatchEvent(new Event("change", { bubbles: true }));
          }),
        ),
      );
      return { node, read: () => [...rows.children].map((row) => row.read()) };
    }
    if (type === "checkbox" || (type === "select" && field.multiple)) {
      const node = el("fieldset", { class: "vf-designer-row" });
      const choices = Object.entries(field.choices || {}).map(
        ([key, title]) => {
          const control = input(key, "checkbox", {
            checked: Array.isArray(value) && value.map(String).includes(key),
          });
          node.append(el("label", { class: "vf-check" }, [control, title]));
          return control;
        },
      );
      return {
        node,
        read: () =>
          choices
            .filter((control) => control.checked)
            .map((control) => control.value),
      };
    }
    if (["gallery", "relationship", "link", "google_map"].includes(type) || field.multiple) {
      const ids = ["gallery", "relationship", "post_object", "user"].includes(type);
      const structured = type === "link" || type === "google_map";
      const node = el("div");
      const editor = el("textarea", {
        class: "vf-code",
        rows: 5,
        "aria-label": (field.label || type) + " JSON value",
      });
      editor.value = value == null || value === "" ? "" : JSON.stringify(value, null, 2);
      const help = ids
        ? 'Enter a JSON list of positive record or attachment IDs, for example [12, 34]. Leave blank to clear.'
        : type === "link"
          ? 'Enter a JSON object with url, optional title and target, for example {"url":"https://example.com","title":"Website"}. Leave blank to clear.'
          : type === "google_map"
            ? 'Enter a JSON object with numeric lat and lng coordinates and an optional address. Leave blank to clear.'
            : 'Enter a JSON list of values. Leave blank to clear.';
      node.append(editor, el("small", { class: "vf-help", text: help }));
      return {
        node,
        read: () => {
          if (!editor.value.trim()) return null;
          let next;
          try { next = JSON.parse(editor.value); }
          catch { throw new Error((field.label || type) + ": enter valid JSON."); }
          if (next === null) return null;
          if (structured) {
            if (typeof next !== "object" || Array.isArray(next)) throw new Error("Enter a structured JSON object.");
            if (type === "link" && (
              Object.keys(next).some((key) => !["url", "title", "target"].includes(key)) ||
              typeof next.url !== "string" ||
              (next.title !== undefined && typeof next.title !== "string") ||
              (next.target !== undefined && !["", "_blank", "_self"].includes(next.target))
            )) throw new Error("Enter a link with url, optional title and a valid target.");
            if (type === "google_map" && (
              !Number.isFinite(next.lat) || Math.abs(next.lat) > 90 ||
              !Number.isFinite(next.lng) || Math.abs(next.lng) > 180 ||
              (next.address !== undefined && typeof next.address !== "string")
            )) throw new Error("Enter map latitude from -90 to 90 and longitude from -180 to 180.");
          } else {
            if (!Array.isArray(next)) throw new Error("Enter a JSON list of values.");
            if (ids && next.some((id) => !Number.isSafeInteger(id) || id <= 0)) throw new Error("IDs must be positive integers.");
          }
          return next;
        },
      };
    }
    const htmlType =
      {
        number: "number",
        range: "number",
        email: "email",
        url: "url",
        password: "password",
        date_picker: "text",
      }[type] || "text";
    const node =
      type === "textarea" || type === "wysiwyg"
        ? el("textarea")
        : input("", htmlType);
    node.value = textValue(value);
    if (htmlType === "number") node.step = "any";
    return {
      node,
      read: () =>
        htmlType === "number" && node.value !== ""
          ? Number(node.value)
          : node.value,
    };
  }
  async function recordDialog(record = null, initialFields = {}, current = captureNavigation()) {
    if (!current()) return false;
    const pending = beginDialog();
    let presentation = null;
    const ownsDialog = () => current() && (presentation ? presentation() : pending());
    const object = state.object;
    let descriptor;
    try {
      if (record) {
        record = await request("record", { id: record.id });
        if (!ownsDialog()) return false;
      }
      descriptor = await request("form", { object: object.slug, id: record?.id || 0 });
    } catch (error) { if (!ownsDialog()) return false; throw error; }
    if (!ownsDialog()) return false;
    const body = el("div");
    const title = input(record?.title || "");
    const status = select(
      {
        draft: "Draft",
        pending: "Pending review",
        publish: "Published",
        private: "Private",
      },
      record?.status || "draft",
    );
    title.disabled = !!record && !descriptor.can_update;
    status.disabled = !!record && !descriptor.can_update;
    body.append(
      el("div", { class: "vf-grid" }, [
        label("Record title", title),
        label("Status", status),
      ]),
    );
    const controls = {};
    const fieldNodes = {};
    Object.entries(descriptor.fields).forEach(([name, field]) => {
      const control = fieldControl(field, record ? record.fields[name] : (initialFields[name] ?? field.default_value));
      control.dirty = Object.hasOwn(initialFields, name);
      control.node.addEventListener("input", () => { control.dirty = true; });
      control.node.addEventListener("change", () => { control.dirty = true; });
      controls[name] = control;
      control.node.disabled =
        field.readonly ||
        (record ? field.can_update === false : field.can_create === false) ||
        field.editable === false;
      if (control.node.disabled)
        control.node
          .querySelectorAll("input,select,textarea,button")
          .forEach((node) => (node.disabled = true));
      fieldNodes[name] = label(
        field.label + (field.required ? " *" : ""),
        control.node,
        field.provider_missing ? "Calculated value may be stale: its runtime provider is unavailable." : "",
      );
    });
    const formFields = el("div");
    if (premium.layout) premium.layout({ descriptor, record, controls, fieldNodes, body, formFields });
    else formFields.append(...Object.values(fieldNodes));
    body.append(formFields);
    if (record) {
      const related = el("div", { class: "vf-related" });
      body.append(related);
      request("related", { id: record.id })
        .then((groups) => {
          if (!ownsDialog()) return;
          groups.forEach((group) => {
            const section = el("section", { class: "vf-designer-row" }, [
              el("h3", { text: `${group.label} · ${group.result.total}` }),
            ]);
            const target = state.objects.find(object => object.slug === group.object);
            const navigate = async (slug, item, fields) => {
              if (!ownsDialog()) return;
              closeDialog();
              const dialogIntent = dialogGeneration;
              let next;
              try { next = await choose(slug); }
              catch (error) { if (dialogIntent === dialogGeneration) throw error; return; }
              if (!next || dialogIntent !== dialogGeneration) return;
              await recordDialog(item, fields, next);
            };
            if (target?.can_create) section.append(button("+ New " + target.singular, safe(() =>
              navigate(group.object, null, {[group.field]: group.multiple ? [record.id] : record.id}))));
            group.result.items.forEach((item) =>
              section.append(button(item.title || "#" + item.id, safe(() => navigate(item.object, item, {})))),
            );
            related.append(section);
          });
        })
        .catch((error) => { if (ownsDialog()) notify(error.message, true); });
      const actions = el("div", { class: "vf-toolbar" }, [
        button(
          "History",
          safe(() => auditDialog(record)),
        ),
        descriptor.can_delete ? button("Delete record", () => {
          const confirmation = el("p", {
            text: `Delete “${record.title}”? Related-record restrictions still apply.`,
          });
          openDialog(
            "Delete record",
            confirmation,
            mutation(({ request }) => request("delete_record", { id: record.id }), async ({ load }) => {
              await load(); notify("Record deleted.");
            }),
            "Delete record",
          );
        }) : null,
      ]);
      body.append(actions);
    }
    openDialog(
      record ? `${descriptor.can_update ? "Edit" : "View"} ${state.object.singular}` : `New ${state.object.singular}`,
      body,
      (record ? descriptor.can_update : descriptor.can_create) ? mutation(async ({ request }) => {
        const fields = {};
        Object.entries(controls).forEach(([name, control]) => {
          if (!control.node.disabled && control.dirty) fields[name] = control.read();
        });
        return request("save_record", {
          object: object.slug,
          id: record?.id || 0,
          payload: {
            title: title.value,
            status: status.value,
            fields,
            ...(record ? { version: record.version } : {}),
          },
        });
      }, async ({ load }) => { await load(); notify("Record saved."); }) : null,
    );
    presentation = captureDialog();
    return ownsDialog;
  }
  function filtersDialog() {
    if (premium.filters) { premium.filters(); return; }
    const body = el("div");
    const rows = el("div");
    const current = structuredClone(state.query.filters || []);
    const fields = {
      title: "Title",
      status: "Status",
      ...Object.fromEntries(
        Object.entries(state.object.fields).map(([name, field]) => [
          name,
          field.label,
        ]),
      ),
    };
    const operators = {
      eq: "Equals",
      ne: "Does not equal",
      contains: "Contains",
      starts_with: "Starts with",
      in: "Is one of",
      gt: "Greater than",
      gte: "At least",
      lt: "Less than",
      lte: "At most",
      between: "Between",
      empty: "Is empty",
      not_empty: "Is not empty",
    };
    const add = (filter = {}) => {
      const field = select(fields, filter.field);
      const op = select(operators, filter.operator || "eq");
      const value = input(
        Array.isArray(filter.value)
          ? filter.value.join(",")
          : filter.value || "",
      );
      const row = el("div", { class: "vf-condition" }, [
        field,
        op,
        value,
        button("×", () => row.remove()),
      ]);
      row.read = () => ({
        field: field.value,
        operator: op.value,
        ...(!["empty", "not_empty"].includes(op.value)
          ? {
              value: ["in", "between"].includes(op.value)
                ? value.value.split(",").map((v) => v.trim())
                : value.value,
            }
          : {}),
      });
      rows.append(row);
    };
    current.forEach(add);
    body.append(
      el("p", {
        class: "vf-help",
        text: "Match all filters. Use comma-separated values for “one of” and “between”.",
      }),
      rows,
      button("+ Add filter", () => add()),
    );
    openDialog(
      "Filter records",
      body,
      async ({ current }) => {
        const intent = beginTable(current);
        state.query.filters = [...rows.children].map((row) => row.read());
        state.query.page = 1;
        await load(intent);
      },
      "Apply filters",
    );
  }
  function columnsDialog() {
    const rows = el("div");
    const sticky = input("", "checkbox", { checked: state.stickyHeader });
    const known = new Map(state.columns.map(column => [column.field, column]));
    [...state.availableColumns, { field: "id", label: "ID" }, { field: "title", label: "Title" }, { field: "status", label: "Status" }, { field: "version", label: "Version" }, ...Object.entries(state.object.fields).map(([field, definition]) => ({ field, label: definition.label || field }))].forEach(column => { if (!known.has(column.field)) known.set(column.field, column); });
    const selected = new Set(state.columns.map(column => column.field));
    for (const column of known.values()) {
      const visible = input("", "checkbox", { checked: selected.has(column.field), "aria-label": `Show ${column.label}` });
      const width = input(column.width || "", "text", { "aria-label": `Width of ${column.label}`, placeholder: "160px or 20%" });
      const row = el("div", { class: "vf-column-control", "data-column": column.field });
      const up = button("↑", () => { if (row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling); });
      up.setAttribute("aria-label", `Move ${column.label} up`);
      const down = button("↓", () => { if (row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row); });
      down.setAttribute("aria-label", `Move ${column.label} down`);
      row.append(label(column.label, visible), width, up, down);
      row.read = () => visible.checked ? { field: column.field, ...(width.value ? { width: width.value } : {}) } : null;
      rows.append(row);
    }
    const body = el("div", {}, [el("p", { class: "vf-help", text: "Choose your columns, their order and widths. Drag table headers to reorder, or use these arrow buttons." }), rows, label("Keep table headers visible while scrolling", sticky)]);
    if (config.configure) body.append(button("Site column defaults", siteColumnsDialog));
    openDialog("Your columns", body, mutation(operation => writePersonalColumns([...rows.children].map(row => row.read()).filter(Boolean), sticky.checked, operation), applyPersonalColumns, true));
  }
  function siteColumnsDialog() {
    const choices = {
      id: "ID",
      title: "Title",
      status: "Status",
      version: "Version",
      ...Object.fromEntries(
        Object.entries(state.object.fields).map(([name, field]) => [
          name,
          field.label,
        ]),
      ),
    };
    const body = el("div");
    const rows = el("div");
    const add = (column = {}) => {
      const field = select(choices, column.field || "title");
      const title = input(column.label || "");
      const width = input(column.width || "");
      const row = el("div", { class: "vf-condition" }, [
        field,
        title,
        width,
        button("×", () => row.remove()),
      ]);
      row.read = () => ({
        field: field.value,
        ...(title.value ? { label: title.value } : {}),
        ...(width.value ? { width: width.value } : {}),
      });
      rows.append(row);
    };
    state.columns.forEach(add);
    body.append(
      el("p", {
        class: "vf-help",
        text: "Columns appear in this order. Optional label and width, such as 160px or 20%.",
      }),
      rows,
      button("+ Add column", () => add()),
    );
    openDialog("Configure columns", body, mutation(({ request }) => request("configure_columns", {
        object: state.object.slug,
        columns: [...rows.children].map((row) => row.read()),
      }), async ({ load }, columns) => { state.columns = columns; await load(); }, true));
  }
  async function auditDialog(record) {
    const ownsDialog = beginDialog(), current = captureNavigation();
    const result = await dialogRequest("audit", { id: record.id }, ownsDialog);
    if (!ownsDialog() || !current()) return;
    const rows = Array.isArray(result) ? result : result.items || [];
    const body = el(
      "div",
      {},
      rows.map((item) =>
        el("article", { class: "vf-audit-item" }, [
          el("strong", { text: `${item.event} · ${item.created_at}` }),
          el("pre", {
            text: JSON.stringify(item.after_data ?? item.after ?? {}, null, 2),
          }),
          premium.restore?.(item),
        ]),
      ),
    );
    if (!rows.length)
      body.append(
        el("p", { text: "No history is available for this record." }),
      );
    openDialog("Record history", body, null);
  }
  function download(file) {
    const url = URL.createObjectURL(
      new Blob([file.content], { type: file.mime_type }),
    );
    const anchor = el("a", { href: url, download: file.filename });
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  async function indexDialog() {
    const ownsDialog = beginDialog();
    const body = el("div", {}, [
      el("p", {
        class: "vf-help",
        text: "Typed field indexes speed up filters. Record permissions and validation still apply. Configuration changes schedule rebuilds automatically.",
      }),
    ]);
    await Promise.all(
      state.objects.map(async (object) => {
        const status = await dialogRequest("index_status", { object: object.slug }, ownsDialog);
        if (!ownsDialog()) return;
        const feedback = el("p", {
          text: status.complete
            ? "Ready"
            : `Rebuild needed. Last record: ${status.cursor}`,
        });
        const rebuild = button(
          "Rebuild next batch",
          dialogAction(mutation(({ request }) => request("index_rebuild", { object: object.slug }), (_operation, next) => {
            feedback.textContent = next.complete ? "Ready" : `Rebuilding. Last record: ${next.cursor}. Continue with the next batch.`;
          })),
        );
        body.append(
          el("section", { class: "vf-designer-row" }, [
            el("strong", { text: object.label }),
            feedback,
            rebuild,
          ]),
        );
      }),
    );
    if (ownsDialog()) openDialog("Field indexes", body, null);
  }
  async function jobsDialog(operation = null) {
    const ownsDialog = operation ? operation.current : beginDialog();
    const data = operation ? await operation.request("jobs", {}) : await dialogRequest("jobs", {}, ownsDialog);
    if (!ownsDialog()) return false;
    const items = Array.isArray(data) ? data : data.items || [];
    const body = el("div");
    if (!items.length)
      body.append(
        el("p", {
          text: "No background jobs yet. Jobs run using WordPress cron.",
        }),
      );
    items.forEach((job) =>
      body.append(
        el("article", { class: "vf-designer-row" }, [
          el("strong", { text: `#${job.id} · ${job.kind} · ${job.status}` }),
          el("p", {
            text: `Attempts: ${job.attempts}. Available: ${job.available_at}`,
          }),
          el("p", { text: job.last_error || "" }),
          ["failed", "retry"].includes(job.status)
            ? button(
                "Retry",
                dialogAction(mutation(({ request }) => request("retry_job", { id: Number(job.id) }), operation => jobsDialog(operation))),
              )
            : null,
        ]),
      ),
    );
    openDialog("Automation jobs", body, null);
  }
  async function designer(slug = "") {
    const ownsDialog = beginDialog(!slug);
    const schema = await dialogRequest("schema", {}, ownsDialog);
    if (!ownsDialog()) return;
    const definition = schema.objects[slug] || {
      slug: "",
      label: "",
      singular: "",
      fields: {},
      access: {},
      automation: [],
      rules: [],
    };
    const editor = window.vfBuildDesigner({
      definition,
      schema,
      roles: config.roles,
    });
    openDialog(
      slug ? "Configure object" : "Create object",
      editor.element,
      mutation(async ({ request }) => {
        const proposed = editor.read();
        await request("save_object", { definition: proposed });
        return proposed;
      }, async (_operation, proposed) => {
        state.object = { slug: proposed.slug };
        return refreshDialog("Object configuration saved.");
      }),
      "Save",
      !slug,
    );
  }
  $("#vf-create-object").addEventListener(
    "click",
    safe(() => designer()),
  );
  const premium = {};
  for (const entry of window.vfWorkspaceExtensions?.actions() || []) Object.assign(premium, entry.factory({ $, el, button, state, notify, request, safe, label, input, select, textValue, openDialog, closeDialog, fieldControl, captureNavigation, navigationAction, captureDialogIntent, captureTable, beginTable, owned, dialogAction, dialogIntent, beginDialog, dialogRequest, mutation, refreshDialog, init, load, download, config }));
  async function configurationExport() { const { request } = owned(captureDialog()); download({ filename: 'vf-configuration.json', content: JSON.stringify(await request('configuration_export'), null, 2), mime_type: 'application/json' }); }
  safe(init)();
})();
