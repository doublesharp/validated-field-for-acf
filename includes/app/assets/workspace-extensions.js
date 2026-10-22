/* Installed workspace providers. Factories receive shared controls and source data. */
(function (global) {
  'use strict';
  const actions = new Map(), sections = new Map(), fields = new Map();
  const register = (map, id, factory) => {
    if (typeof id !== 'string' || !id || typeof factory !== 'function' || map.has(id)) throw new Error('Invalid or duplicate workspace extension: ' + id);
    map.set(id, factory);
  };
  const entries = map => Array.from(map, ([id, factory]) => ({ id, factory }));
  global.vfWorkspaceExtensions = Object.freeze({
    registerAction: (id, factory) => register(actions, id, factory),
    registerDesignerSection: (id, factory) => register(sections, id, factory),
    registerFieldEditor: (id, factory) => register(fields, id, factory),
    actions: () => entries(actions), designerSections: () => entries(sections), fieldEditors: () => entries(fields)
  });
})(window);
