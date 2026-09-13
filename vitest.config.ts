import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		// `tests/e2e/**/*.test.ts` is deliberately narrow. The e2e specs
		// themselves are `.spec.ts` and belong to playwright, so this picks
		// up the pure-logic modules under tests/e2e and nothing that needs a
		// browser.
		include: ['src/**/*.spec.ts', 'tests/e2e/**/*.test.ts'],
		css: false,
	},
})
