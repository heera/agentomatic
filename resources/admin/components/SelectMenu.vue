<script>
/**
 * A custom, theme-styled dropdown — a drop-in replacement for a native <select>
 * whose option list can't be styled. The trigger reuses the shared `.ar-input`
 * box so it matches the height and border of the text fields beside it.
 *
 * Options accept plain strings or { value, label } objects. Emits update:modelValue
 * so it works with v-model. Closes on outside click, Esc, or a pick; supports
 * arrow-key navigation.
 */
export default {
  name: 'SelectMenu',
  props: {
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    ariaLabel: { type: String, default: '' },
    mono: { type: Boolean, default: false }, // monospace values (e.g. model IDs)
  },
  emits: ['update:modelValue'],
  data() {
    return { open: false, activeIndex: -1 };
  },
  computed: {
    items() {
      return this.options.map((o) => (o && typeof o === 'object' ? o : { value: o, label: String(o) }));
    },
    selectedLabel() {
      const hit = this.items.find((i) => i.value === this.modelValue);
      return hit ? hit.label : (this.modelValue || this.placeholder);
    },
  },
  beforeUnmount() {
    this.detach();
  },
  methods: {
    toggle() {
      if (this.open) this.close();
      else this.openMenu();
    },
    openMenu() {
      this.open = true;
      this.activeIndex = Math.max(0, this.items.findIndex((i) => i.value === this.modelValue));
      document.addEventListener('mousedown', this.onDocDown, true);
      document.addEventListener('keydown', this.onKey, true);
    },
    close() {
      this.open = false;
      this.detach();
    },
    detach() {
      document.removeEventListener('mousedown', this.onDocDown, true);
      document.removeEventListener('keydown', this.onKey, true);
    },
    pick(item) {
      this.$emit('update:modelValue', item.value);
      this.close();
      this.$nextTick(() => { if (this.$refs.btn) this.$refs.btn.focus(); });
    },
    onDocDown(e) {
      if (this.$el && !this.$el.contains(e.target)) this.close();
    },
    onKey(e) {
      if (!this.open) return;
      if (e.key === 'Escape') { e.preventDefault(); this.close(); if (this.$refs.btn) this.$refs.btn.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); this.activeIndex = Math.min(this.items.length - 1, this.activeIndex + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeIndex = Math.max(0, this.activeIndex - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); const it = this.items[this.activeIndex]; if (it) this.pick(it); }
    },
  },
};
</script>

<template>
  <div class="ar-select" :class="{ 'is-open': open, 'ar-select--mono': mono }">
    <button
      ref="btn"
      type="button"
      class="ar-input ar-select__btn"
      :aria-label="ariaLabel"
      aria-haspopup="listbox"
      :aria-expanded="open ? 'true' : 'false'"
      @click="toggle"
    >
      <span class="ar-select__value">{{ selectedLabel }}</span>
      <span class="ar-select__caret" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
      </span>
    </button>
    <ul v-if="open" class="ar-select__menu" role="listbox">
      <li
        v-for="(it, i) in items"
        :key="it.value"
        class="ar-select__opt"
        :class="{ 'is-active': i === activeIndex, 'is-selected': it.value === modelValue }"
        role="option"
        :aria-selected="it.value === modelValue ? 'true' : 'false'"
        @mouseenter="activeIndex = i"
        @click="pick(it)"
      >{{ it.label }}</li>
    </ul>
  </div>
</template>

<style scoped>
.ar-select { position: relative; }
.ar-select__btn {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  width: 100%; text-align: left; cursor: pointer;
}
.ar-select__value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ar-select--mono .ar-select__value { font-family: var(--ar-mono); }
.ar-select__caret { flex: 0 0 auto; display: inline-flex; color: var(--ar-ink-faint); transition: transform 0.15s ease; }
.ar-select.is-open .ar-select__caret { transform: rotate(180deg); }
.ar-select.is-open .ar-select__btn { border-color: var(--ar-accent); box-shadow: 0 0 0 3px rgba(20, 107, 100, 0.13); }

.ar-select__menu {
  position: absolute; z-index: 40; top: calc(100% + 5px); left: 0; right: 0;
  margin: 0; padding: 4px; list-style: none;
  background: var(--ar-paper); border: 1px solid var(--ar-line-strong);
  border-radius: var(--ar-radius); box-shadow: 0 12px 30px -12px rgba(27, 25, 19, 0.4);
  max-height: 260px; overflow: auto;
}
.ar-select__opt {
  padding: 7px 10px; border-radius: calc(var(--ar-radius) - 2px);
  font-size: 13px; color: var(--ar-ink); cursor: pointer; white-space: nowrap;
}
.ar-select--mono .ar-select__opt { font-family: var(--ar-mono); font-size: 12px; }
.ar-select__opt.is-active { background: var(--ar-surface-2); }
.ar-select__opt.is-selected { color: var(--ar-accent); font-weight: 600; }
</style>
