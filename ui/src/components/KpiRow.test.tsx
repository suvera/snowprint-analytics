import { render, screen } from '@testing-library/react';
import type { Overview } from '../api';
import { KpiRow } from './KpiRow';

const metrics = { visitors: 1200, visits: 1500, pageviews: 4000, events: 10, views_per_visit: 2.7, bounce_rate: 48, visit_duration: 95 };

test('deltas are coloured by whether up is good', () => {
  const overview: Overview = {
    site: 'example.com',
    period: { from: '2026-10-03', to: '2026-10-09', timezone: 'UTC' },
    current: metrics,
    previous: metrics,
    change: { visitors: 20, visits: 0, pageviews: null, events: 0, views_per_visit: 0, bounce_rate: 10, visit_duration: -5 },
  };
  render(<KpiRow overview={overview} metric="visitors" onMetric={() => undefined} />);

  expect(screen.getByText(/\+20% vs previous/).className).toContain('good');      // more visitors: good
  expect(screen.getByText(/\+10% vs previous/).className).toContain('bad');       // higher bounce rate: bad
  expect(screen.getByText(/-5% vs previous/).className).toContain('bad');         // shorter visits: bad
  expect(screen.getByText('no earlier data')).toBeTruthy();
  expect(screen.getByRole('button', { name: /Visitors/ }).getAttribute('aria-pressed')).toBe('true');
});
