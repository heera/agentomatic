<script>
export default {
  name: 'TagInput',
  props: {
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Add and press Enter' },
  },
  emits: ['update:modelValue'],
  data() {
    return { draft: '' };
  },
  methods: {
    add() {
      const value = this.draft.trim();
      if (!value || this.modelValue.includes(value)) {
        this.draft = '';
        return;
      }
      this.$emit('update:modelValue', [...this.modelValue, value]);
      this.draft = '';
    },
    remove(index) {
      const next = this.modelValue.slice();
      next.splice(index, 1);
      this.$emit('update:modelValue', next);
    },
    onKeydown(e) {
      if (e.key === 'Backspace' && !this.draft && this.modelValue.length) {
        this.remove(this.modelValue.length - 1);
      }
    },
  },
};
</script>

<template>
  <div class="ar-tags">
    <ul class="ar-tags__list">
      <li v-for="(tag, i) in modelValue" :key="tag" class="ar-tags__chip">
        <span>{{ tag }}</span>
        <button type="button" class="ar-tags__x" :aria-label="`Remove ${tag}`" @click="remove(i)">×</button>
      </li>
    </ul>
    <input
      v-model="draft"
      type="text"
      class="ar-tags__input"
      :placeholder="placeholder"
      @keydown.enter.prevent="add"
      @keydown="onKeydown"
      @blur="add"
    />
  </div>
</template>
