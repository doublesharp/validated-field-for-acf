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
  const state = {
    objects: [],
    object: null,
    query: { page: 1, per_page: 20 },
    rows: [],
    columns: [],
    views: [],
    selected: new Set(),
    generation: 0,
  };
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
        notify(error.message, true);
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
  function openDialog(title, body, submit, saveLabel = "Save") {
    returnFocus = document.activeElement;
    $("#vf-dialog-title").textContent = title;
    $("#vf-dialog-body").replaceChildren(body);
    $("#vf-dialog-submit").textContent = saveLabel;
    $("#vf-dialog-submit").hidden = !submit;
    dialogSubmit = submit;
    $("#vf-dialog").showModal();
    setTimeout(
      () =>
        $("#vf-dialog-body")
          .querySelector("input,select,textarea,button")
          ?.focus(),
      0,
    );
  }
  function closeDialog() {
    $("#vf-dialog").close();
    returnFocus?.focus();
  }
  $("#vf-dialog")
    .querySelectorAll("[data-close]")
    .forEach((node) => node.addEventListener("click", closeDialog));
  $("#vf-dialog-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!dialogSubmit) return;
    const submit = $("#vf-dialog-submit");
    submit.disabled = true;
    $("#vf-dialog-body").querySelector(".vf-dialog-error")?.remove();
    try {
      await dialogSubmit();
      closeDialog();
    } catch (error) {
      $("#vf-dialog-body").prepend(
        el("div", {
          class: "vf-alert vf-error vf-dialog-error",
          role: "alert",
          text: error.message,
        }),
      );
    } finally {
      submit.disabled = false;
    }
  });
  async function init() {
    state.objects = await request("objects");
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
        button("Background jobs", safe(jobsDialog)),
        button("Field indexes", safe(indexDialog)),
        button("Configuration transfer", safe(() => premium.configuration ? premium.configuration() : configurationExport())),
      );
    $("#vf-create-object").hidden = !config.configure;
    if (state.objects.length) {
      const params = new URLSearchParams(location.search);
      await choose(
        state.object?.slug || params.get("object") || state.objects[0].slug,
      );
      if (params.has("record")) {
        const record = await request("record", {
          id: Number(params.get("record")),
        });
        if (record.object !== state.object.slug) await choose(record.object);
        await recordDialog(record);
        history.replaceState(
          null,
          "",
          location.pathname + "?page=validated-field-workspace",
        );
      }
    } else {
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
  }
  async function choose(slug) {
    const navigation = ++state.generation;
    state.object = state.objects.find((object) => object.slug === slug);
    if (!state.object) return;
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
    if (config.configure)
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
    const [columns, views] = await Promise.all([
      request("columns", { object: slug }),
      premium.views ? premium.views(slug) : Promise.resolve([]),
    ]);
    if (navigation !== state.generation) return;
    state.columns = columns;
    state.views = views;
    await load();
  }
  async function load() {
    const generation = ++state.generation;
    $("#vf-content").setAttribute("aria-busy", "true");
    const result = await request("query", {
      object: state.object.slug,
      query: state.query,
    });
    if (generation !== state.generation) return;
    state.rows = result.items;
    state.selected.clear();
    renderList(result);
    $("#vf-content").setAttribute("aria-busy", "false");
  }
  function renderList(result) {
    const search = input(state.query.search || "", "search", {
      "aria-label": "Search records",
      placeholder: "Search records…",
    });
    let timer;
    search.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(
        safe(async () => {
          state.query.search = search.value;
          state.query.page = 1;
          await load();
        }),
        350,
      );
    });
    const toolbar = el("div", { class: "vf-toolbar" }, [
      search,
      button("Filters", filtersDialog),
    ]);
    if (config.configure) toolbar.append(button("Columns", columnsDialog));
    premium.toolbar?.(toolbar);
    if (state.object.can_export)
      toolbar.append(
        button(
          "Export CSV",
          safe(async () => {
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
    const all = input("", "checkbox", { "aria-label": "Select current page" });
    all.addEventListener("change", () => {
      state.selected.clear();
      if (all.checked) state.rows.forEach((row) => state.selected.add(row.id));
      $("#vf-content")
        .querySelectorAll("[data-row-select]")
        .forEach((node) => (node.checked = all.checked));
    });
    const head = el("tr", {}, [
      el("th", {}, [all]),
      ...state.columns.map((column) => {
        const title = column.sortable
          ? el("button", {
              class: "vf-sort",
              type: "button",
              text:
                column.label +
                (state.query.sort?.field === column.field
                  ? state.query.sort.direction === "asc"
                    ? " ↑"
                    : " ↓"
                  : ""),
              onclick: safe(async () => {
                state.query.sort = {
                  field: column.field,
                  direction:
                    state.query.sort?.field === column.field &&
                    state.query.sort.direction === "asc"
                      ? "desc"
                      : "asc",
                };
                await load();
              }),
            })
          : el("span", { text: column.label });
        return el(
          "th",
          {
            scope: "col",
            ...(column.width ? { style: `width:${column.width}` } : {}),
          },
          [title],
        );
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
                  onclick: safe(() => recordDialog(row)),
                }),
              );
            else if (column.editable && premium.inline)
              cell.append(
                el("button", {
                  type: "button",
                  class: "vf-cell",
                  text: display || "—",
                  title: "Edit value",
                  onclick: safe(() => premium.inline(row, column)),
                }),
              );
            else cell.textContent = display || "—";
            return cell;
          }),
          el("td", {}, [
            button(
              "Open",
              safe(() => recordDialog(row)),
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
        state.query.page--;
        await load();
      }),
    );
    previous.disabled = result.page <= 1;
    const next = button(
      "Next",
      safe(async () => {
        state.query.page++;
        await load();
      }),
    );
    next.disabled = result.page >= result.total_pages;
    $("#vf-content").replaceChildren(
      toolbar,
      el("div", { class: "vf-table-wrap" }, [
        el("table", { class: "vf-table" }, [el("thead", {}, [head]), tbody]),
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
    );
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
        feedback.textContent = "Searching records…";
        request("query", {
          object: field.relationship.object,
          query: { per_page: 100, ...(term ? { search: term } : {}) },
        })
        .then(async (result) => {
          if (current !== generation) return;
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
            if (record.id === id && record.object === field.relationship.object)
              if (options.has(id)) options.get(id).textContent = record.title || "#" + id;
          }));
        })
        .catch((error) => {
          if (current !== generation) return;
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
        if (!window.wp?.media) { notify("The WordPress media library could not load.", true); return; }
        const frame = window.wp.media({title:"Choose " + field.label, multiple:false, ...(type === "image" ? {library:{type:"image"}} : {})});
        frame.on("select", () => {const selected = frame.state().get("selection").first().toJSON();attachment=selected.id;summary.textContent=selected.filename || selected.title;node.dispatchEvent(new Event("change",{bubbles:true}));});
        frame.on("close", () => {if (!$("#vf-dialog").open) $("#vf-dialog").showModal();});
        $("#vf-dialog").close(); frame.open();
      });
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
      const node = select({ "": "Choose…", ...field.choices }, value);
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
  async function recordDialog(record = null, initialFields = {}) {
    if (record) record = await request("record", { id: record.id });
    const descriptor = await request("form", {
      object: state.object.slug,
      id: record?.id || 0,
    });
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
          groups.forEach((group) => {
            const section = el("section", { class: "vf-designer-row" }, [
              el("h3", { text: `${group.label} · ${group.result.total}` }),
            ]);
            const target = state.objects.find(object => object.slug === group.object);
            if (target?.can_create) section.append(button("+ New " + target.singular, safe(async () => {
              closeDialog(); await choose(group.object);
              await recordDialog(null, {[group.field]: group.multiple ? [record.id] : record.id});
            })));
            group.result.items.forEach((item) =>
              section.append(
                button(
                  item.title || "#" + item.id,
                  safe(async () => {
                    await choose(item.object);
                    closeDialog();
                    await recordDialog(item);
                  }),
                ),
              ),
            );
            related.append(section);
          });
        })
        .catch((error) => notify(error.message, true));
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
            async () => {
              await request("delete_record", { id: record.id });
              await load();
              notify("Record deleted.");
            },
            "Delete record",
          );
        }) : null,
      ]);
      body.append(actions);
    }
    openDialog(
      record ? `${descriptor.can_update ? "Edit" : "View"} ${state.object.singular}` : `New ${state.object.singular}`,
      body,
      (record ? descriptor.can_update : descriptor.can_create) ? async () => {
        const fields = {};
        Object.entries(controls).forEach(([name, control]) => {
          if (!control.node.disabled && control.dirty) fields[name] = control.read();
        });
        await request("save_record", {
          object: state.object.slug,
          id: record?.id || 0,
          payload: {
            title: title.value,
            status: status.value,
            fields,
            ...(record ? { version: record.version } : {}),
          },
        });
        await load();
        notify("Record saved.");
      } : null,
    );
  }
  function filtersDialog() {
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
      async () => {
        state.query.filters = [...rows.children].map((row) => row.read());
        state.query.page = 1;
        await load();
      },
      "Apply filters",
    );
  }
  function columnsDialog() {
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
    openDialog("Configure columns", body, async () => {
      state.columns = await request("configure_columns", {
        object: state.object.slug,
        columns: [...rows.children].map((row) => row.read()),
      });
      await load();
    });
  }
  async function auditDialog(record) {
    const result = await request("audit", { id: record.id });
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
    const body = el("div", {}, [
      el("p", {
        class: "vf-help",
        text: "Typed field indexes speed up filters. Record permissions and validation still apply. Configuration changes schedule rebuilds automatically.",
      }),
    ]);
    await Promise.all(
      state.objects.map(async (object) => {
        const status = await request("index_status", { object: object.slug });
        const feedback = el("p", {
          text: status.complete
            ? "Ready"
            : `Rebuild needed. Last record: ${status.cursor}`,
        });
        const rebuild = button(
          "Rebuild next batch",
          safe(async () => {
            rebuild.disabled = true;
            try {
              const next = await request("index_rebuild", {
                object: object.slug,
              });
              feedback.textContent = next.complete
                ? "Ready"
                : `Rebuilding. Last record: ${next.cursor}. Continue with the next batch.`;
            } finally {
              rebuild.disabled = false;
            }
          }),
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
    openDialog("Field indexes", body, null);
  }
  async function jobsDialog() {
    const data = await request("jobs");
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
                safe(async () => {
                  await request("retry_job", { id: Number(job.id) });
                  closeDialog();
                  await jobsDialog();
                }),
              )
            : null,
        ]),
      ),
    );
    openDialog("Automation jobs", body, null);
  }
  async function designer(slug = "") {
    const schema = await request("schema");
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
      async () => {
        const proposed = editor.read();
        await request("save_object", { definition: proposed });
        state.object = { slug: proposed.slug };
        await init();
        notify("Object configuration saved.");
      },
    );
  }
  $("#vf-create-object").addEventListener(
    "click",
    safe(() => designer()),
  );
  const premium = {};
  for (const entry of window.vfWorkspaceExtensions?.actions() || []) Object.assign(premium, entry.factory({ $, el, button, state, notify, request, safe, label, input, select, textValue, openDialog, closeDialog, fieldControl, load, download, config }));
  async function configurationExport() { download({ filename: 'vf-configuration.json', content: JSON.stringify(await request('configuration_export'), null, 2), mime_type: 'application/json' }); }
  safe(init)();
})();
