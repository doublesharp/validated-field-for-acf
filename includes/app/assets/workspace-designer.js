/* Structured configuration only. Persistence and authorization belong to the shared services. */
(function (global) {
'use strict';

const own = (value, key) => value != null && Object.prototype.hasOwnProperty.call(value, key);
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const put = (target, key, value) => Object.defineProperty(target, key, {
  value, enumerable: true, configurable: true, writable: true
});

function clone(value) {
  if (Array.isArray(value)) return value.map(clone);
  if (!object(value)) return value;
  const copy = {};
  Object.entries(value).forEach(([key, child]) => put(copy, key, clone(child)));
  return copy;
}

function same(a, b) {
  if (Object.is(a, b)) return true;
  if (Array.isArray(a) !== Array.isArray(b) || !a || !b || typeof a !== 'object' || typeof b !== 'object') return false;
  const keys = Object.keys(a), otherKeys = Object.keys(b);
  return keys.length === otherKeys.length && keys.every((key, index) => key === otherKeys[index] && same(a[key], b[key]));
}

function mergeSettings(base, changes = {}, removed = []) {
  const result = object(base) ? clone(base) : {};
  removed.forEach(key => delete result[key]);
  Object.entries(changes).forEach(([key, value]) => put(result, key, clone(value)));
  return result;
}

function parseTyped(text, type) {
  if (type === 'string') return String(text);
  if (type === 'null') return null;
  if (type === 'boolean') {
    if (text === true || text === 'true') return true;
    if (text === false || text === 'false') return false;
    throw new Error('Choose a boolean value: true or false.');
  }
  if (type === 'number') {
    const value = String(text).trim();
    if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value) || !Number.isFinite(Number(value))) {
      throw new Error('Enter a finite decimal number.');
    }
    return Number(value);
  }
  throw new Error('Unsupported value type.');
}

const operators = {
  eq: 'Equals', ne: 'Does not equal', contains: 'Contains', starts_with: 'Starts with',
  in: 'Is in a list', gt: 'Greater than', gte: 'At least', lt: 'Less than', lte: 'At most',
  between: 'Between two bounds', empty: 'Is empty', not_empty: 'Is not empty'
};

function serializeCondition(base, spec) {
  const removed = ['all', 'any', 'field', 'operator', 'value', 'value_field'];
  if (spec.mode === 'all' || spec.mode === 'any') {
    return mergeSettings(base, { [spec.mode]: clone(spec.children || []) }, removed);
  }
  const next = { field: spec.field, operator: spec.operator };
  if (!['empty', 'not_empty'].includes(spec.operator)) {
    if (spec.source === 'field') next.value_field = spec.value_field;
    else {
      if (spec.operator === 'between' && (!Array.isArray(spec.value) || spec.value.length !== 2)) throw new Error('Between needs exactly two bounds.');
      if (spec.operator === 'in' && !Array.isArray(spec.value)) throw new Error('In needs a list of values.');
      next.value = clone(spec.value);
    }
  }
  return mergeSettings(base, next, removed);
}

function serializeRoleAccess(base, mode, roles) {
  return mode === 'native' ? mergeSettings(base, {}, ['roles']) : mergeSettings(base, { roles });
}

function serializeEntries(rows) {
  const result = {};
  rows.forEach(({ key, value }) => {
    if (own(result, key)) throw new Error(`Duplicate name: ${key}.`);
    put(result, key, clone(value));
  });
  return result;
}

function serializeFields(rows) {
  const reserved = ['id', 'object', 'title', 'status', 'version'];
  rows.forEach(({ name }) => {
    if (!/^[a-z][a-z0-9_]{0,63}$/.test(name)) throw new Error('Every field needs a stable lowercase API name, up to 64 characters.');
    if (reserved.includes(name)) throw new Error(`Field name ${name} is reserved.`);
  });
  return serializeEntries(rows.map(({ name, value }) => ({ key: name, value })));
}

function serializeCollection(rows, original) {
  return object(original) ? serializeEntries(rows) : rows.map(row => clone(row.value));
}

function createDraft(original) {
  const baseline = clone(original);
  const bindings = new Map();
  return {
    bind(key, editor) { bindings.set(key, editor); return editor.element; },
    read() {
      const changes = {};
      const removed = [];
      bindings.forEach((editor, key) => {
        const value = editor.read();
        if (same(value, baseline?.[key])) return;
        if (value === undefined) removed.push(key);
        else put(changes, key, value);
      });
      return Object.keys(changes).length || removed.length ? mergeSettings(baseline, changes, removed) : clone(baseline);
    }
  };
}

function validateLayout(layout, fieldNames) {
  if (!object(layout) || !Array.isArray(layout.tabs)) throw new Error('A structured layout needs a tabs list.');
  const declared = new Set(fieldNames);
  const used = new Set();
  layout.tabs.forEach(tab => {
    if (!object(tab) || !Array.isArray(tab.sections)) throw new Error('Every layout tab needs a sections list.');
    tab.sections.forEach(section => {
      if (!object(section) || !Array.isArray(section.fields)) throw new Error('Every layout section needs an ordered fields list.');
      section.fields.forEach(name => {
        if (!declared.has(name)) throw new Error(`Layout field ${String(name)} must be a declared field.`);
        if (used.has(name)) throw new Error(`Place layout field ${name} only once.`);
        used.add(name);
      });
    });
  });
  return true;
}

function validateRecordTypes(types, fieldNames, baseline = []) {
  if (!Array.isArray(types)) throw new Error('Record types must be a list.');
  const existing = Array.isArray(baseline) ? baseline : [];
  const ids = new Set();
  types.forEach(type => {
    const before = existing.find(item => object(item) && item.id === type?.id);
    if (!object(type)) {
      if (existing.some(item => same(item, type))) return;
      throw new Error('Each record type needs an ID, label, discriminator field and scalar value.');
    }
    if (ids.has(type.id)) throw new Error('Every record type needs a different ID. Duplicate IDs are not allowed.');
    ids.add(type.id);
    if (same(type, before)) return;
    if (!before || !same(type.id, before.id)) {
      if (typeof type.id !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(type.id)) throw new Error('Record type IDs use letters, digits, underscores or hyphens.');
    }
    if ((!before || !same(type.field, before.field)) && !fieldNames.includes(type.field)) throw new Error('Record type discriminators must use a declared field.');
    if ((!before || !same(type.value, before.value)) && (!own(type, 'value') || (type.value !== null && !['string', 'number', 'boolean'].includes(typeof type.value)))) throw new Error('Record type matching values must be scalar.');
    if (type.layout !== undefined && (!before || !same(type.layout, before.layout))) validateLayout(type.layout, fieldNames);
  });
  return true;
}

function validateCondition(condition) {
  let nodes = 0;
  const path = /^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,3}$/;
  const scalar = value => value === null || ['string', 'boolean'].includes(typeof value) || (typeof value === 'number' && Number.isFinite(value));
  const visit = (item, depth) => {
    if (!object(item) || depth > 8 || ++nodes > 128) throw new Error('Conditions allow eight nested levels and 128 nodes.');
    const mode = own(item, 'all') ? 'all' : own(item, 'any') ? 'any' : '';
    if (mode) {
      if (!Array.isArray(item[mode]) || !item[mode].length) throw new Error('Every condition group needs at least one condition.');
      if (item[mode].length > 64) throw new Error('A condition group allows at most 64 children.');
      item[mode].forEach(child => visit(child, depth + 1));
      return;
    }
    if (typeof item.field !== 'string' || !path.test(item.field)) throw new Error('Choose a declared field or relationship path for each comparison.');
    if (!own(operators, item.operator)) throw new Error('Choose a supported condition operator.');
    if (['empty', 'not_empty'].includes(item.operator)) return;
    if (own(item, 'value_field')) {
      if (typeof item.value_field !== 'string' || !path.test(item.value_field)) throw new Error('Choose a valid right-hand field.');
      return;
    }
    if (['in', 'between'].includes(item.operator)) {
      if (!Array.isArray(item.value) || item.value.length > 100) throw new Error('This comparison needs a list of at most 100 scalar values.');
      if (item.operator === 'between' && item.value.length !== 2) throw new Error('Between needs exactly two bounds.');
      if (!item.value.every(scalar)) throw new Error('Comparison list items must be scalar values.');
    } else if (!own(item, 'value') || !scalar(item.value)) throw new Error('Comparison values must be scalar.');
  };
  // Extension keys are retained; the shared server remains authoritative for them.
  visit(condition, 0);
  return true;
}

function uniqueScalarField(field) {
  if (!object(field)) return false;
  if (field.type === 'validated_field') field = mergeSettings(field, { type: 'text', ...(object(field.sub_field) ? field.sub_field : {}) });
  if (field.multiple && field.multiple !== '0') return false;
  const presetTypes = { phone: 'text', money: 'number', currency: 'select', country: 'select', address: 'group', date_range: 'group', file: 'file' };
  const preset = field.business?.preset;
  const type = own(presetTypes, preset) ? presetTypes[preset] : field.type;
  return [
    'text', 'textarea', 'email', 'url', 'password', 'select', 'radio', 'button_group',
    'number', 'range', 'true_false', 'date_picker', 'date_time_picker', 'time_picker',
    'color_picker', 'user', 'post_object', 'image', 'file'
  ].includes(type);
}

function validateUniqueConstraints(constraints, fields) {
  const invalid = (message, constraintIndex, componentIndex) => {
    const error = new Error(message);
    error.constraintIndex = constraintIndex;
    error.componentIndex = componentIndex;
    throw error;
  };
  if (!Array.isArray(constraints)) invalid('Unique constraints must be a list.');
  if (constraints.length > 32) invalid('An object allows at most 32 unique constraints.');
  const names = new Set();
  constraints.forEach((constraint, index) => {
    if (!object(constraint) || typeof constraint.name !== 'string' || !/^[a-z][a-z0-9_]{0,63}$/.test(constraint.name)) {
      invalid('Every unique constraint needs a stable lowercase name, up to 64 letters, digits or underscores.', index);
    }
    if (names.has(constraint.name)) invalid('Every unique constraint needs a different name. Duplicate names are not allowed.', index);
    names.add(constraint.name);
    if (!Array.isArray(constraint.fields) || constraint.fields.length < 2 || constraint.fields.length > 8) {
      invalid('A unique constraint needs 2 through 8 distinct scalar fields.', index);
    }
    const used = new Set();
    constraint.fields.forEach((name, component) => {
      if (typeof name !== 'string' || !own(fields, name)) invalid('Each unique component must name a declared top-level field.', index, component);
      if (used.has(name)) invalid('Each unique component must use a different field. Duplicate fields are not allowed.', index, component);
      if (!uniqueScalarField(fields[name])) invalid(`Unique component ${name} must be a scalar field with a single value.`, index, component);
      used.add(name);
    });
  });
  return true;
}

let instance = 0;

function buildDesigner({ definition = {}, schema = {}, roles = {} } = {}) {
  if (!global || !global.document) throw new Error('The designer requires a browser document.');
  const document = global.document;
  const freeze = value => { if (value && typeof value === 'object') { Object.values(value).forEach(freeze); Object.freeze(value); } return value; };
  const source = freeze(clone(definition));
  const prefix = `vfd-${++instance}`;
  let sequence = 0;
  let readFieldChoices = () => Object.entries(source.fields || {}).map(([name, field]) => [name, field.label || name]);
  const uid = () => `${prefix}-${++sequence}`;
  const node = (tag, attributes = {}, children = []) => {
    const result = document.createElement(tag);
    Object.entries(attributes).forEach(([key, value]) => {
      if (key === 'text') result.textContent = String(value ?? '');
      else if (['checked', 'disabled', 'hidden', 'value', 'open'].includes(key)) result[key] = value;
      else result.setAttribute(key, String(value));
    });
    children.flat().filter(child => child !== undefined && child !== null).forEach(child => {
      result.append(child.nodeType ? child : document.createTextNode(String(child)));
    });
    return result;
  };
  const button = (title, action, attrs = {}) => {
    const result = node('button', { type: 'button', class: 'vfd-button', text: title, ...attrs });
    result.addEventListener('click', action);
    return result;
  };
  const help = text => node('p', { class: 'vfd-help', text });
  const grid = (...children) => node('div', { class: 'vfd-grid' }, children);
  const details = (title, ...children) => node('details', { class: 'vfd-details' }, [node('summary', { text: title }), ...children]);
  const legend = (title, ...children) => node('fieldset', { class: 'vfd-fieldset' }, [node('legend', { text: title }), ...children]);
  const label = (title, control, hint = '') => {
    if (!control.id) control.id = uid();
    const hintId = uid();
    if (hint) control.setAttribute('aria-describedby', hintId);
    return node('div', { class: 'vfd-control' }, [
      node('label', { for: control.id, text: title }), control,
      hint ? node('small', { id: hintId, text: hint }) : null
    ]);
  };
  const input = (value = '', type = 'text') => node('input', { type, value: value ?? '' });
  const choose = (choices, value = '') => {
    const entries = Object.entries(choices);
    if (!entries.some(([key]) => key === String(value))) entries.push([String(value), String(value)]);
    return node('select', {}, entries.map(([key, title]) => node('option', { value: key, text: title, ...(key === String(value) ? { selected: 'selected' } : {}) })));
  };
  const fail = (control, message) => {
    control.setAttribute('aria-invalid', 'true');
    const error = new Error(message);
    error.control = control;
    throw error;
  };
  const scalarControl = (value, kind = 'text', choices) => {
    const control = choices ? choose(choices, value ?? Object.keys(choices)[0] ?? '') : kind === 'textarea' ? node('textarea', { rows: 3, value: value ?? '' }) : input(value, kind === 'checkbox' ? 'checkbox' : kind === 'number' ? 'text' : kind);
    if (kind === 'checkbox') control.checked = !!value && value !== '0' && value !== 'false';
    if (kind === 'number') control.setAttribute('inputmode', 'decimal');
    let dirty = false;
    const mark = () => { dirty = true; control.removeAttribute('aria-invalid'); };
    control.addEventListener('input', mark);
    control.addEventListener('change', mark);
    return {
      element: control, dirty: () => dirty,
      read: () => {
        if (!dirty) return clone(value);
        if (kind === 'checkbox') return control.checked;
        if (kind === 'number') {
          try { return parseTyped(control.value, 'number'); } catch (error) { return fail(control, error.message); }
        }
        return control.value;
      }
    };
  };

  // Every reader starts with the original JSON and patches only changed children.
  function model(original) {
    return createDraft(original);
  }

  function property(editor, original, key, title, kind = 'text', hint = '', choices) {
    const control = scalarControl(original?.[key], kind, choices);
    editor.bind(key, control);
    return { ...control, element: label(title, control.element, hint), control: control.element };
  }

  function fieldReference(value, title = 'Field', hint = '', choices = () => readFieldChoices()) {
    const control = scalarControl(value);
    const list = node('datalist', { id: uid() });
    control.element.setAttribute('list', list.id);
    control.element.addEventListener('focus', () => {
      list.replaceChildren(...choices().map(([key, text]) => node('option', { value: key, text })));
    });
    return { ...control, control: control.element, element: node('div', {}, [label(title, control.element, hint), list]) };
  }

  function objectReference(value, title = 'Object') {
    const options = Object.fromEntries(Object.entries(schema.objects || {}).map(([key, item]) => [key, item.label || key]));
    if (source.slug) put(options, source.slug, source.label || source.slug);
    const control = scalarControl(value, 'text', { '': 'Choose an object', ...options });
    return { ...control, element: label(title, control.element) };
  }

  function optional(original, title, make, seed = {}) {
    const enabled = scalarControl(original !== undefined, 'checkbox');
    const editor = make(original === undefined ? clone(seed) : original);
    const content = node('div', { hidden: !enabled.element.checked }, [editor.element]);
    enabled.element.addEventListener('change', () => { content.hidden = !enabled.element.checked; });
    return {
      element: legend(title, label(`Enable ${title.toLowerCase()}`, enabled.element), content),
      read: () => {
        if (!enabled.element.checked) return enabled.dirty() ? undefined : clone(original);
        const value = editor.read();
        if (enabled.dirty()) editor.validate?.(value);
        return value;
      }
    };
  }

  // Lists preserve their original array/map shape and every untouched entry.
  function collection(original, { title, make, create = () => ({}), keyOf, fields = false, minimum = 0, maximum = Infinity, open = false }) {
    if (original !== undefined && !Array.isArray(original) && !object(original)) {
      return { element: help(`${title}: an existing extension value is retained. Remove or replace it in its owning integration.`), read: () => clone(original) };
    }
    const rows = [];
    const container = node('div', { class: 'vfd-list' });
    const status = node('span', { class: 'vfd-sr', 'aria-live': 'polite' });
    const entries = Array.isArray(original) ? original.map((value, index) => [String(index), value]) : Object.entries(original || {});
    const originalKeys = entries.map(([key]) => key);
    const refresh = () => {
      rows.forEach((row, index) => {
        row.up.disabled = index === 0;
        row.down.disabled = index === rows.length - 1;
        row.remove.disabled = rows.length <= minimum;
        const caption = row.editor.caption?.();
        row.summary.textContent = `${title} ${index + 1}${caption ? `: ${caption}` : ''}`;
      });
      add.disabled = rows.length >= maximum;
      container.replaceChildren(...rows.map(row => row.element));
    };
    const insert = (value, key, isNew = false) => {
      const editor = make(value, key, isNew);
      const summary = node('summary', { text: title });
      const row = { editor, key, summary };
      const move = delta => {
        const index = rows.indexOf(row);
        const next = index + delta;
        if (next < 0 || next >= rows.length) return;
        rows.splice(index, 1); rows.splice(next, 0, row);
        refresh();
        (delta < 0 ? row.up : row.down).focus();
        status.textContent = `${title} moved to position ${next + 1}.`;
      };
      row.up = button('Move up', () => move(-1), { 'aria-label': `Move ${title.toLowerCase()} up` });
      row.down = button('Move down', () => move(1), { 'aria-label': `Move ${title.toLowerCase()} down` });
      row.remove = button('Remove', () => {
        const index = rows.indexOf(row);
        rows.splice(index, 1); refresh();
        (rows[index]?.summary || rows[index - 1]?.summary || add).focus();
        status.textContent = `${title} removed.`;
      }, { 'aria-label': `Remove ${title.toLowerCase()}` });
      row.element = node('details', { class: 'vfd-card', open: isNew || open }, [summary,
        node('div', { class: 'vfd-toolbar' }, [row.up, row.down, row.remove]), editor.element]);
      row.element.addEventListener('input', () => {
        const caption = row.editor.caption?.();
        row.summary.textContent = `${title} ${rows.indexOf(row) + 1}${caption ? `: ${caption}` : ''}`;
      });
      rows.push(row);
      return row;
    };
    const add = button(`+ Add ${title.toLowerCase()}`, () => {
      const row = insert(create(), `new_${uid()}`, true);
      refresh();
      row.editor.element.querySelector('input,select,textarea,button')?.focus();
      status.textContent = `${title} added.`;
    });
    entries.forEach(([key, value]) => insert(value, key));
    refresh();
    return {
      element: node('div', { class: 'vfd-collection' }, [container, add, status]),
      editorAt: index => rows[index]?.editor,
      choices: () => rows.map(row => row.editor.choice?.()).filter(Boolean),
      read() {
        const values = rows.map(row => ({ key: keyOf ? keyOf(row.editor, row.key) : row.key, value: row.editor.read() }));
        const unchanged = rows.length === entries.length && rows.every((row, index) => row.key === originalKeys[index] && same(values[index].value, entries[index][1]) && (!keyOf || values[index].key === originalKeys[index]));
        if (unchanged) return clone(original);
        if (fields) return serializeFields(values.map(row => ({ name: row.key, value: row.value })));
        return serializeCollection(values, original);
      }
    };
  }

  function typedValue(original, title = 'Value', { scalar = false, depth = 0 } = {}) {
    if (depth > 12) return { element: help('This deeply nested value is retained without modification.'), read: () => clone(original) };
    const typeOf = value => value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value === 'object' ? 'object' : typeof value === 'boolean' ? 'boolean' : typeof value === 'number' ? 'number' : 'string';
    const initialType = typeOf(original);
    const types = { string: 'Text', number: 'Number', boolean: 'Boolean', null: 'Null', ...(!scalar ? { array: 'List', object: 'Object' } : {}) };
    const type = scalarControl(initialType, 'text', types);
    const body = node('div');
    let currentType = initialType;
    let editor;
    const render = (value, kind) => {
      currentType = kind;
      if (kind === 'array') {
        editor = collection(value, { title: 'Value', create: () => '', make: item => typedValue(item, 'List item', { depth: depth + 1 }) });
      } else if (kind === 'object') {
        editor = collection(value, { title: 'Property', create: () => '', keyOf: child => child.key(), make: (item, key, isNew) => {
          const name = scalarControl(isNew ? '' : key);
          const child = typedValue(item, 'Property value', { depth: depth + 1 });
          return { element: node('div', {}, [label('Property name', name.element), child.element]), key: () => name.element.value, read: child.read };
        } });
      } else {
        editor = scalarControl(kind === 'null' ? null : value, kind === 'number' ? 'number' : 'text', kind === 'boolean' ? { true: 'True', false: 'False' } : undefined);
        if (kind === 'null') editor.element.hidden = true;
        const basic = editor;
        editor = { ...basic, read: () => {
          if (kind === 'null') return null;
          if (!basic.dirty() && !type.dirty()) return clone(value);
          try { return parseTyped(basic.element.value, kind); } catch (error) { return fail(basic.element, error.message); }
        } };
        editor.element = label(`${title} content`, basic.element);
      }
      body.replaceChildren(editor.element);
    };
    type.element.addEventListener('change', () => {
      const kind = type.element.value;
      const seed = kind === initialType ? original : { string: '', number: 0, boolean: false, null: null, array: [], object: {} }[kind];
      render(seed, kind);
    });
    render(original, initialType);
    return {
      element: legend(title, label('Value type', type.element), body),
      read: () => {
        const value = editor.read();
        return !type.dirty() && same(value, original) ? clone(original) : currentType === 'null' ? null : value;
      }
    };
  }

  function extensions(original, known) {
    const unknown = Object.fromEntries(Object.entries(object(original) ? original : {}).filter(([key]) => !known.includes(key)));
    if (!Object.keys(unknown).length) return null;
    const area = scalarControl(JSON.stringify(unknown, null, 2), 'textarea');
    area.element.className = 'vfd-json';
    area.element.spellcheck = false;
    return {
      element: details('Extension settings', label('Unknown extension settings', area.element, 'Optional JSON for settings owned by installed extensions. Known settings use the structured controls above.')),
      read() {
        if (!area.dirty()) return clone(unknown);
        let parsed;
        try { parsed = JSON.parse(area.element.value); } catch { return fail(area.element, 'Extension settings must be valid JSON.'); }
        if (!object(parsed)) return fail(area.element, 'Extension settings must be a JSON object.');
        if (Object.keys(parsed).some(key => known.includes(key))) return fail(area.element, 'Use the structured controls for known settings.');
        return parsed;
      }, keys: Object.keys(unknown)
    };
  }

  function finish(editor, original, content, known) {
    const extension = extensions(original, known);
    if (extension) content.append(extension.element);
    return {
      element: content,
      read() {
        const value = editor.read();
        if (!extension) return value;
        const next = extension.read();
        return same(next, Object.fromEntries(extension.keys.map(key => [key, original[key]]))) ? value : mergeSettings(value, next, extension.keys);
      }
    };
  }

  function textList(original, title, numeric = false) {
    return collection(original, { title, create: () => numeric ? 1 : '', make: value => {
      const control = scalarControl(value, numeric ? 'number' : 'text');
      return { ...control, element: label(title, control.element) };
    } });
  }

  function rolePicker(original, title, allowNative = true) {
    const mode = scalarControl(original === undefined ? 'native' : 'restricted', 'text', allowNative
      ? { native: 'Native WordPress capabilities', restricted: 'Only selected roles' }
      : { restricted: 'Only selected roles' });
    const available = Object.fromEntries(Object.entries(roles).map(([key, value]) => [key, typeof value === 'object' ? value.name || value.label || key : value]));
    (Array.isArray(original) ? original : []).forEach(role => { if (!own(available, role)) put(available, role, `${role} (existing role)`); });
    const controls = Object.entries(available).map(([key, title]) => ({ key, control: scalarControl(Array.isArray(original) && original.includes(key), 'checkbox'), title }));
    const list = node('div', { class: 'vfd-roles' }, controls.map(({ control, title }) => label(title, control.element)));
    const message = help('No selected roles means deny all. These restrictions still require native capabilities.');
    const refresh = () => { list.hidden = mode.element.value === 'native'; message.hidden = list.hidden; };
    mode.element.addEventListener('change', refresh); refresh();
    return {
      element: legend(title, label('Role access', mode.element), list, message),
      read() {
        if (!mode.dirty() && controls.every(({ control }) => !control.dirty())) return clone(original);
        return mode.element.value === 'native' ? undefined : controls.filter(({ control }) => control.element.checked).map(({ key }) => key);
      }
    };
  }

  const premium = {};
  const extensionContext = { source, metadata: schema.metadata || {}, operators, own, object, clone, same, mergeSettings, parseTyped, serializeCondition, serializeRoleAccess, validateCondition, validateLayout, validateRecordTypes, node, button, help, grid, details, legend, label, input, choose, fail, scalarControl, model, property, fieldReference, objectReference, optional, collection, typedValue, extensions, finish, textList, rolePicker, editorNumber, schema, readFieldChoices: () => readFieldChoices() };
  for (const entry of global?.vfWorkspaceExtensions?.designerSections() || []) Object.assign(premium, entry.factory(extensionContext));
  const nativeTypes = {
    text: 'Text', textarea: 'Long text', number: 'Number', range: 'Range', email: 'Email', url: 'URL', password: 'Password',
    true_false: 'Checkbox', select: 'Select', checkbox: 'Checkboxes', radio: 'Radio buttons', button_group: 'Button group',
    date_picker: 'Date', date_time_picker: 'Date and time', time_picker: 'Time', color_picker: 'Color',
    post_object: 'Related record', relationship: 'Multiple related records', user: 'User', taxonomy: 'Taxonomy',
    file: 'File', image: 'Image', gallery: 'Gallery (Pro)', wysiwyg: 'Rich text', oembed: 'Embed', google_map: 'Map',
    group: 'Group', repeater: 'Repeater (Pro)', flexible_content: 'Flexible content (Pro)', clone: 'Clone (Pro)',
    tab: 'ACF tab', accordion: 'ACF accordion', message: 'Message', validated_field: 'Validated field (legacy wrapper)'
  };
  const presetMetadata = schema.metadata?.presets || {};
  const defaultPresets = { phone: 'Phone', money: 'Money', currency: 'Currency', country: 'Country', address: 'Address', date_range: 'Date range', file: 'File' };

  function businessEditor(original = {}) {
    if (!object(original)) return { element: help('Existing business settings retained.'), read: () => clone(original) };
    const editor = model(original);
    const presetChoices = { '': 'Choose a business preset', ...defaultPresets, ...Object.fromEntries(Object.entries(presetMetadata).map(([key, value]) => [key, value.label || key])) };
    const preset = property(editor, original, 'preset', 'Business preset', 'text', '', presetChoices);
    const sections = {};
    const numberSetting = (key, title, hint = '') => {
      const seeded = optional(original[key], title, value => {
        const field = scalarControl(value, 'number');
        return { ...field, element: label(title, field.element, hint) };
      }, 0);
      return editor.bind(key, seeded);
    };
    sections.money = node('div', {}, [numberSetting('precision', 'Decimal places'), numberSetting('min', 'Minimum amount'), numberSetting('max', 'Maximum amount')]);
    sections.allowed = node('div', {}, [editor.bind('allowed', textList(original.allowed, 'Allowed code'))]);
    sections.address = node('div', {}, [editor.bind('required_parts', textList(original.required_parts, 'Required address part'))]);
    sections.date_range = node('div', {}, [numberSetting('max_days', 'Maximum range in days')]);
    sections.file = node('div', {}, [numberSetting('max_bytes', 'Maximum file bytes'), editor.bind('mime_types', textList(original.mime_types, 'Allowed MIME type'))]);
    const refresh = () => {
      Object.entries(sections).forEach(([name, section]) => { section.hidden = name === 'allowed' ? !['currency', 'country'].includes(preset.control.value) : preset.control.value !== name; });
    };
    preset.control.addEventListener('change', refresh); refresh();
    return finish(editor, original, node('div', {}, [preset.element, help('The server expands presets to native ACF fields and validates the constraints.'), ...Object.values(sections)]), ['preset', 'precision', 'min', 'max', 'allowed', 'required_parts', 'max_days', 'max_bytes', 'mime_types']);
  }

  function relationshipEditor(original) {
    if (!object(original)) return { element: help('Existing relationship settings retained.'), read: () => clone(original) };
    const editor = model(original);
    return finish(editor, original, node('div', {}, [
      editor.bind('object', objectReference(original.object, 'Related object')),
      ...(premium.relationship?.(editor, original) || []),
      property(editor, original, 'required', 'Require a reference', 'checkbox').element
    ]), ['object', 'kind', 'on_delete', 'required']);
  }

  function fieldEditor(original, fallbackName = '', nested = false, depth = 0) {
    if (!object(original) || depth > 10) return { element: help('Existing field definition retained without modification.'), read: () => clone(original), name: () => original?.name || fallbackName };
    const editor = model(original);
    const name = property(editor, original, 'name', 'API name', 'text', 'Stable lowercase identifier. Renaming does not migrate stored values.');
    if (!own(original, 'name')) name.control.value = fallbackName;
    const title = property(editor, original, 'label', 'Label');
    const installed = schema.field_types || schema.metadata?.field_types || {};
    const types = { ...nativeTypes };
    if (Array.isArray(installed)) installed.forEach(type => { if (typeof type === 'string') put(types, type, type); else if (type?.name) put(types, type.name, type.label || type.name); });
    else Object.entries(installed).forEach(([key, value]) => put(types, key, typeof value === 'string' ? value : value.label || key));
    const type = property(editor, original, 'type', 'Native ACF type', 'text', 'Only installed types can be saved. Existing custom types and wrappers stay intact.', types);
    const required = property(editor, original, 'required', 'Required', 'checkbox');
    const readonly = property(editor, original, 'readonly', 'Read only', 'checkbox');
    const unique = property(editor, original, 'unique', 'Unique value', 'checkbox');
    const basic = grid(title.element, name.element, type.element, required.element, readonly.element, unique.element);
    const choices = typedValue(original.choices === undefined ? {} : original.choices, 'Choices');
    const choicesEditor = { ...choices, read: () => { const value = choices.read(); return original.choices === undefined && same(value, {}) ? undefined : value; } };
    const defaults = optional(original.default_value, 'Default value', value => typedValue(value, 'Default'), '');
    const settings = details('Choices, defaults and native constraints',
      property(editor, original, 'instructions', 'Instructions', 'textarea').element,
      property(editor, original, 'placeholder', 'Placeholder').element,
      editor.bind('choices', choicesEditor), editor.bind('default_value', defaults),
      grid(...['min', 'max', 'step', 'minlength', 'maxlength', 'rows'].map(key => editor.bind(key,
        optional(original[key], key, value => {
          const control = scalarControl(value, 'number');
          return { ...control, element: label(key, control.element) };
        }, key === 'step' ? 1 : 0)))),
      grid(property(editor, original, 'multiple', 'Multiple values', 'checkbox').element,
        property(editor, original, 'allow_null', 'Allow no value', 'checkbox').element,
        property(editor, original, 'return_format', 'Return format').element,
        property(editor, original, 'display_format', 'Display format').element)
    );
    const logic = node('div');
    const fieldContext = { original, editor, content: logic };
    premium.field?.(fieldContext);
    for (const entry of global?.vfWorkspaceExtensions?.fieldEditors() || []) {
      const contribution = entry.factory({ ...extensionContext, ...fieldContext, source: clone(original) });
      if (contribution?.element) logic.append(contribution.element);
    }
    const business = optional(original.business, 'Business preset', businessEditor, { preset: '' });
    const relationship = optional(original.relationship, 'Relationship', relationshipEditor, { object: '', kind: 'lookup', on_delete: 'restrict', required: false });
    const content = node('div', {}, [basic, settings, logic, editor.bind('business', business), editor.bind('relationship', relationship)]);
    if (!nested) content.append(details('Field access', editor.bind('read_roles', rolePicker(original.read_roles, 'Read roles')), editor.bind('write_roles', rolePicker(original.write_roles, 'Write roles'))));
    else content.append(help('Access restrictions belong on the top-level containing field. Existing nested settings are retained.'));
    const children = collection(original.sub_fields, { title: 'Nested field', create: () => ({ name: '', label: '', type: 'text', required: false }), make: (value, key) => fieldEditor(value, Array.isArray(original.sub_fields) ? '' : key, true, depth + 1) });
    const childHolder = details('Group or repeater fields', editor.bind('sub_fields', children));
    content.append(childHolder);
    const layouts = collection(original.layouts, { title: 'Flexible layout', create: () => ({ name: '', label: '', display: 'block', sub_fields: [] }), make: item => {
      if (!object(item)) return { element: help('Existing layout retained.'), read: () => clone(item) };
      const layout = model(item);
      const fields = collection(item.sub_fields, { title: 'Layout field', create: () => ({ name: '', label: '', type: 'text', required: false }), make: value => fieldEditor(value, '', true, depth + 1) });
      return finish(layout, item, node('div', {}, [grid(property(layout, item, 'label', 'Layout label').element,
        property(layout, item, 'name', 'Layout API name').element),
        property(layout, item, 'display', 'Display', 'text', '', { block: 'Block', table: 'Table', row: 'Row' }).element,
        editorNumber(layout, item, 'min', 'Minimum rows'), editorNumber(layout, item, 'max', 'Maximum rows'),
        layout.bind('sub_fields', fields)]), ['key', 'name', 'label', 'display', 'min', 'max', 'sub_fields']);
    } });
    const layoutHolder = details('Flexible content layouts', editor.bind('layouts', layouts));
    content.append(layoutHolder);
    let wrapped;
    if (own(original, 'sub_field')) {
      wrapped = fieldEditor(original.sub_field, original.sub_field?.name || '', nested, depth + 1);
      content.append(details('Wrapped native field', help('The validated wrapper and its legacy settings are retained.'), editor.bind('sub_field', wrapped)));
    }
    const refresh = () => {
      childHolder.hidden = !['group', 'repeater'].includes(type.control.value) && !own(original, 'sub_fields');
      layoutHolder.hidden = type.control.value !== 'flexible_content' && !own(original, 'layouts');
    };
    type.control.addEventListener('change', refresh); refresh();
    const known = ['key', 'name', 'label', 'type', 'required', 'readonly', 'unique', 'instructions', 'placeholder', 'choices', 'default_value',
      'min', 'max', 'step', 'minlength', 'maxlength', 'rows', 'multiple', 'allow_null', 'return_format', 'display_format',
      'required_when', 'readonly_when', 'conditional_defaults', 'vf_rules', 'formula', 'rollup', 'business', 'relationship',
      'read_roles', 'write_roles', 'sub_fields', 'layouts', 'sub_field'];
    const completed = finish(editor, original, content, known);
    const stableName = () => !nested && !name.dirty() && fallbackName ? fallbackName : name.control.value;
    return { ...completed, name: stableName, identityControl: name.control,
      caption: () => title.control.value || stableName(), choice: () => [stableName(), title.control.value || stableName()] };
  }

  function editorNumber(editor, original, key, title) {
    return editor.bind(key, optional(original[key], title, value => {
      const control = scalarControl(value, 'number');
      return { ...control, element: label(title, control.element) };
    }, 0));
  }

  function uniquenessEditor(original) {
    const scalarChoices = () => {
      let definitions;
      try { definitions = fields.read(); }
      catch { definitions = source.fields; }
      return readFieldChoices().filter(([name]) => uniqueScalarField(definitions?.[name]));
    };
    const constraints = collection(original, {
      title: 'Unique constraint', maximum: 32,
      create: () => ({ name: '', fields: ['', ''] }),
      make: (constraint, key, isNew) => {
        if (!object(constraint)) return { element: help('Existing extension constraint retained.'), read: () => clone(constraint) };
        const editor = model(constraint);
        const name = property(editor, constraint, 'name', 'Constraint name', 'text',
          'Stable lowercase identifier. To change an existing identity, remove the constraint and add a new one.');
        name.control.disabled = !isNew && typeof constraint.name === 'string' && !!constraint.name;
        const components = collection(constraint.fields, {
          title: 'Component', minimum: 2, maximum: 8, create: () => '',
          make: value => fieldReference(value, 'Component field', 'Choose a declared scalar field with a single value. Relationship paths and containers are not supported.', scalarChoices)
        });
        const completed = finish(editor, constraint, node('div', {}, [
          name.element, legend('Ordered tuple components', editor.bind('fields', components))
        ]), ['name', 'fields']);
        return { ...completed, caption: () => name.control.value, identityControl: name.control,
          componentControl: index => components.editorAt(index)?.control };
      }
    });
    const element = node('div', { tabindex: '-1' }, [
      help('Require a combination of fields to be unique across records. Add up to 32 constraints, each containing 2 through 8 distinct scalar fields.'),
      help('If any component is empty, its tuple is not claimed. False and zero are real values. The server normalizes email and numeric components before checking uniqueness.'),
      constraints.element
    ]);
    return {
      element, read: constraints.read,
      validate(value, fields) {
        try { return validateUniqueConstraints(value, fields); }
        catch (error) {
          const constraint = constraints.editorAt(error.constraintIndex);
          const control = error.componentIndex === undefined ? constraint?.identityControl : constraint?.componentControl(error.componentIndex);
          return fail(control || element, error.message);
        }
      }
    };
  }

  const rootModel = model(source);
  const slug = property(rootModel, source, 'slug', 'Stable API name', 'text', 'Lowercase letters, digits and underscores, up to 20 characters.');
  // Existing object identities cannot be renamed by this editor.
  slug.control.disabled = !!source.slug && own(schema.objects || {}, source.slug);
  const metadata = node('div', {}, [grid(property(rootModel, source, 'label', 'Object name').element,
    property(rootModel, source, 'singular', 'Singular name').element), slug.element,
    help('Managed objects require authenticated access. Record and field permissions apply to every application request.'),
    property(rootModel, source, 'existing', 'Link an existing post type', 'checkbox', 'Enable only when deliberately linking a registered type.').element]);
  const fields = collection(source.fields, { title: 'Field', fields: true, keyOf: child => child.name(),
    create: () => ({ name: '', label: '', type: 'text', required: false }), make: (field, key) => fieldEditor(field, key) });
  readFieldChoices = fields.choices;
  const uniqueness = uniquenessEditor(source.unique_constraints);
  rootModel.bind('fields', fields); rootModel.bind('unique_constraints', uniqueness);
  const extraSections = premium.sections?.({ source, rootModel }) || [];
  const tabs = node('div', { class: 'vfd-tabs', role: 'tablist', 'aria-label': 'Object designer sections' });
  const panels = node('div', { class: 'vfd-panels' });
  if (!extraSections.length) {
    const content = [help('Visual rules, automation, calculations and custom record layouts are available with Validated Field Pro. Existing settings are retained.')];
    const upgradeUrl = global?.vfWorkspace?.proUpgradeUrl;
    if (upgradeUrl && !global?.vfWorkspace?.proActive) content.push(node('a', { href: upgradeUrl, target: '_blank', rel: 'noopener noreferrer', text: 'Learn about Validated Field Pro' }));
    metadata.append(node('div', {}, content));
  }
  const sectionList = [['Object', metadata], ['Fields', fields.element], ['Uniqueness', uniqueness.element], ...extraSections];
  const tabRows = [];
  const activate = (index, focus = false) => {
    tabRows.forEach(({ tab, panel }, position) => {
      const selected = position === index;
      tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
      panel.hidden = !selected;
    });
    if (focus) tabRows[index].tab.focus();
  };
  sectionList.forEach(([title, body], index) => {
    const tabId = uid(); const panelId = uid();
    const tab = button(title, () => activate(index), { id: tabId, role: 'tab', 'aria-controls': panelId });
    const panel = node('section', { id: panelId, class: 'vfd-panel', role: 'tabpanel', 'aria-labelledby': tabId, tabindex: '0' }, [body]);
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % sectionList.length;
      else if (event.key === 'ArrowLeft') next = (index + sectionList.length - 1) % sectionList.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = sectionList.length - 1;
      if (next !== undefined) { event.preventDefault(); activate(next, true); }
    });
    tabRows.push({ tab, panel }); tabs.append(tab); panels.append(panel);
  });
  activate(0);
  const completed = finish(rootModel, source, node('div', { class: 'vfd-designer' }, [tabs, panels]), [
    'slug', 'label', 'singular', 'public', 'existing', 'fields', 'access', 'rules', 'automation', 'layout', 'record_types', 'transitions', 'approvals', 'relationships', 'vf_rules', 'unique_constraints', '_revision'
  ]);
  return {
    element: completed.element,
    read() {
      try {
        const result = completed.read();
        const names = Object.keys(result.fields || {});
        if (!same(result.unique_constraints, source.unique_constraints)) uniqueness.validate(result.unique_constraints, result.fields);
        if (object(result.layout) && !same(result.layout.tabs, source.layout?.tabs)) validateLayout(result.layout, names);
        if (Array.isArray(result.record_types) && !same(result.record_types, source.record_types)) validateRecordTypes(result.record_types, names, source.record_types);
        return result;
      }
      catch (error) {
        if (error.control) {
          const index = tabRows.findIndex(({ panel }) => panel.contains(error.control));
          if (index >= 0) activate(index);
          let parent = error.control.parentElement;
          while (parent && parent !== completed.element) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            if (parent.hidden) parent.hidden = false;
            parent = parent.parentElement;
          }
          error.control.focus();
        }
        throw error;
      }
    }
  };
}

const helpers = { buildDesigner, clone, same, mergeSettings, parseTyped, serializeCondition, serializeRoleAccess,
  serializeEntries, serializeFields, serializeCollection, createDraft, validateLayout, validateRecordTypes, validateCondition,
  uniqueScalarField, validateUniqueConstraints };
if (typeof module === 'object' && module.exports) module.exports = helpers;
if (global && global.document) global.vfBuildDesigner = buildDesigner;
})(typeof window === 'object' ? window : null);
