// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * Versioniq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the OpenRegister
 * landing page at openregister.conduction.nl (docs/src/pages/index.js).
 *
 * Versioniq is in development — this page tells visitors what the
 * app is (a version-rollback / pin tool that spans App Store + GitHub
 * releases, see openspec/specs/{version-management,external-sources,
 * pat-management,app-discovery}) and previews the three core surfaces
 * with token-built mock panels. No <AppMock> yet (no versioniq
 * variant in the preset).
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* Version-rows glyph — two stacked rows with a version marker, lifted
   straight from the apps-catalog entry on conduction-website. */
const VERSIONIQ_ICON = (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M3 6h18v4H3zM3 14h12v4H3z" />
    <circle cx="19" cy="16" r="2" />
  </svg>
);

const TAGLINE = (
  <>
    Rol een app terug naar een eerdere versie als productie kapot is,
    of pin alvast op een nieuwere versie om compatibility te testen
    voordat je je hele Nextcloud meeneemt. Eén UI over de Nextcloud
    App Store én GitHub releases. In ontwikkeling.
  </>
);

/* --- Token-built mock widget panels -----------------------------------
   Sketches of the three surfaces Versioniq will land with: the
   list of installed apps + their current version, the version picker
   that fans out per source, and a feed of recent rollbacks. */

function InstalledAppsPanel() {
  const rows = [
    { tone: 'var(--c-cobalt-300)', app: 'openregister', v: '0.7.9' },
    { tone: 'var(--c-mint-500)', app: 'opencatalogi', v: '1.4.2' },
    { tone: 'var(--c-lavender-300)', app: 'openconnector', v: '0.5.1' },
    { tone: 'var(--c-forest-300)', app: 'docudesk', v: '0.3.0' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.app}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              fontWeight: 700,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-500)',
            }}
          >
            v{row.v}
          </div>
        </div>
      ))}
    </div>
  );
}

function AvailableVersionsPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', v: '0.8.0', src: 'appstore', tag: 'LATEST' },
    { tone: 'var(--c-cobalt-300)', v: '0.7.9', src: 'appstore', tag: 'CURRENT' },
    { tone: 'var(--c-cobalt-300)', v: '0.7.8', src: 'appstore', tag: '' },
    { tone: 'var(--c-lavender-300)', v: '0.9.0-rc', src: 'github', tag: 'BETA' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              fontWeight: 700,
              color: 'var(--c-cobalt-700)',
              minWidth: 56,
            }}
          >
            v{row.v}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-500)',
              flex: 1,
            }}
          >
            {row.src}
          </div>
          {row.tag && (
            <div
              style={{
                fontFamily: 'var(--conduction-typography-font-family-code)',
                fontSize: 7,
                fontWeight: 700,
                letterSpacing: '0.08em',
                color: 'var(--c-cobalt-700)',
                background: 'var(--c-cobalt-50)',
                padding: '1px 4px',
                borderRadius: 1,
              }}
            >
              {row.tag}
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

function RecentRollbacksPanel() {
  const rows = [
    { tone: 'var(--c-orange-knvb)', app: 'openconnector', from: '0.6.0', to: '0.5.1', when: '2h ago' },
    { tone: 'var(--c-cobalt-300)', app: 'docudesk', from: '0.4.0', to: '0.3.0', when: 'today' },
    { tone: 'var(--c-mint-500)', app: 'openregister', from: '0.7.8', to: '0.7.9', when: 'yesterday' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-700)',
            }}
          >
            {row.app}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              color: 'var(--c-cobalt-500)',
            }}
          >
            v{row.from} → v{row.to}
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 7,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-500)',
              minWidth: 50,
              textAlign: 'right',
            }}
          >
            {row.when}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Installed apps + current version',
    desc: 'Alfabetisch de apps die op je instance staan, met de versie die er nu draait. Eén klik op een rij opent de version picker.',
    panel: <InstalledAppsPanel />,
  },
  {
    title: 'Available versions, per source',
    desc: 'De Nextcloud App Store en GitHub releases naast elkaar. Tags zoals LATEST en BETA staan erbij, zodat je niet per ongeluk een release candidate live zet — tenzij je dat wil.',
    panel: <AvailableVersionsPanel />,
  },
  {
    title: 'Recent rollbacks',
    desc: 'De laatste downgrades en pins, met wie het wanneer deed. Handig om snel terug te zien wat de oorzaak was van een fix, of om een rollback van een collega ongedaan te maken.',
    panel: <RecentRollbacksPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Versioniq"
      description="Install any earlier or newer version of already installed Nextcloud apps. Essential for debugging, testing compatibility, and recovering from breaking changes."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          status={{ label: 'In development', color: 'var(--c-orange-knvb)' }}
          version="pre-release"
          locales="NL · EN"
          title="Versioniq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/versioniq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          iconColor="var(--c-orange-knvb)"
          icon={VERSIONIQ_ICON}
        />

        <WidgetShelf
          eyebrow="What Versioniq will land with"
          title="Versiebeheer voor je hele Nextcloud — App Store én GitHub."
          lede="Een breaking change in een minor release sloopt soms productie. Versioniq geeft je de uitknop: rol terug naar de versie die wél werkte, pin op een release candidate om compatibility te testen, of installeer een private GitHub release als je app daar leeft. Eén interface, één audit trail."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
