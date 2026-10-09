import { fireEvent, render, screen, within } from '@testing-library/react';
import type { BreakdownRow } from '../api';
import { DataTable } from './DataTable';

const row = (value: string, visitors: number, change: number | null = null): BreakdownRow => ({
  value, visitors, visits: visitors + 1, pageviews: visitors * 2, events: 0, bounce_rate: 40, visit_duration: 75,
  previous_visitors: change === null ? null : 1, change,
});

const ROWS = [row('DE', 30, 50), row('US', 60, -25), row('IN', 10)];

function names(): string[] {
  return screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0].textContent ?? '');
}

test('sorted by visitors, with share, change and country names', () => {
  render(<DataTable rows={ROWS} dimension="country" column="Country" totalVisitors={100} />);
  expect(names()).toEqual(['United States', 'Germany', 'India']);
  const us = screen.getAllByRole('row')[1];
  expect(within(us).getByText('60%')).toBeTruthy();       // share of 100 visitors
  expect(within(us).getByText('▼ 25%')).toBeTruthy();
  expect(within(screen.getAllByRole('row')[3]).getByText('new')).toBeTruthy();
  expect(within(us).getByText('1m 15s')).toBeTruthy();
});

test('clicking a header sorts, clicking again reverses', () => {
  render(<DataTable rows={ROWS} dimension="country" column="Country" totalVisitors={100} />);
  fireEvent.click(screen.getByRole('button', { name: 'Country' }));
  expect(names()).toEqual(['Germany', 'India', 'United States']);
  fireEvent.click(screen.getByRole('button', { name: /Country/ }));
  expect(names()).toEqual(['United States', 'India', 'Germany']);
});

test('search matches raw values and display names', () => {
  render(<DataTable rows={ROWS} dimension="country" column="Country" totalVisitors={100} />);
  fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'germ' } });
  expect(names()).toEqual(['Germany']);
  expect(screen.getByText('1 of 3')).toBeTruthy();
});

test('rows filter the dashboard when clicked', () => {
  const onFilter = vi.fn();
  render(<DataTable rows={ROWS} dimension="country" column="Country" totalVisitors={100} onFilter={onFilter} />);
  fireEvent.click(screen.getByRole('button', { name: 'Germany' }));
  expect(onFilter).toHaveBeenCalledWith('DE');
});
