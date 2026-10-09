// Dashboard sections: the left menu and the dimensions each section explores.

export interface Tab {
  dimension: string;   // StatsQuery dimension
  label: string;       // tab label
  column: string;      // table heading for the value
  filter?: false;      // rows cannot become a filter (entry/exit pages)
}

export interface Section {
  id: string;
  label: string;
  icon: string;        // SVG path data, 24x24 stroke icon
  tabs?: Tab[];
}

export const SECTIONS: Section[] = [
  { id: 'overview', label: 'Overview', icon: 'M4 4h7v7H4zM13 4h7v4h-7zM13 10h7v10h-7zM4 13h7v7H4z' },
  {
    id: 'sources', label: 'Sources', icon: 'M3 12h12M11 6l6 6-6 6M21 4v16',
    tabs: [
      { dimension: 'source', label: 'Sources', column: 'Source' },
      { dimension: 'referrer', label: 'Referrers', column: 'Referrer' },
      { dimension: 'utm_source', label: 'UTM source', column: 'UTM source' },
      { dimension: 'utm_medium', label: 'UTM medium', column: 'UTM medium' },
      { dimension: 'utm_campaign', label: 'UTM campaign', column: 'UTM campaign' },
      { dimension: 'utm_term', label: 'UTM term', column: 'UTM term' },
      { dimension: 'utm_content', label: 'UTM content', column: 'UTM content' },
    ],
  },
  {
    id: 'pages', label: 'Pages', icon: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7',
    tabs: [
      { dimension: 'page', label: 'Top pages', column: 'Page' },
      { dimension: 'entry_page', label: 'Entry pages', column: 'Entry page', filter: false },
      { dimension: 'exit_page', label: 'Exit pages', column: 'Exit page', filter: false },
      { dimension: 'hostname', label: 'Hostnames', column: 'Hostname' },
    ],
  },
  {
    id: 'locations', label: 'Locations', icon: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c2.5 2.7 3.7 5.7 3.7 9s-1.2 6.3-3.7 9c-2.5-2.7-3.7-5.7-3.7-9S9.5 5.7 12 3z',
    tabs: [
      { dimension: 'country', label: 'Countries', column: 'Country' },
      { dimension: 'region', label: 'Regions', column: 'Region' },
      { dimension: 'city', label: 'Cities', column: 'City' },
    ],
  },
  {
    id: 'devices', label: 'Devices', icon: 'M3 5h18v11H3zM8 20h8M12 16v4',
    tabs: [
      { dimension: 'browser', label: 'Browsers', column: 'Browser' },
      { dimension: 'os', label: 'Operating systems', column: 'Operating system' },
      { dimension: 'device', label: 'Device types', column: 'Device type' },
    ],
  },
  { id: 'events', label: 'Events & goals', icon: 'M5 21V4M5 4h11l-2 4 2 4H5' },
  { id: 'realtime', label: 'Realtime', icon: 'M3 12h4l3-7 4 14 3-7h4' },
];

export function section(id: string | undefined): Section {
  return SECTIONS.find((s) => s.id === id) ?? SECTIONS[0];
}

/** Country codes to names, e.g. "DE" -> "Germany" (falls back to the code). */
const regionNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
export function displayValue(dimension: string, value: string): string {
  if (dimension === 'country' && /^[A-Z]{2}$/.test(value) && regionNames) {
    return regionNames.of(value) ?? value;
  }
  return value;
}
