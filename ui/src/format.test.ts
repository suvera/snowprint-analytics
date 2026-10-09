import { formatChange, formatDuration, formatNumber, niceTicks } from './format';

test('numbers compact above 10k', () => {
  expect(formatNumber(1284)).toBe('1,284');
  expect(formatNumber(12_900)).toBe('12.9K');
  expect(formatNumber(4_200_000)).toBe('4.2M');
});

test('durations', () => {
  expect(formatDuration(42)).toBe('42s');
  expect(formatDuration(150)).toBe('2m 30s');
  expect(formatDuration(3720)).toBe('1h 2m');
});

test('signed change, null when there is no earlier data', () => {
  expect(formatChange(12.4)).toBe('+12%');
  expect(formatChange(-3)).toBe('-3%');
  expect(formatChange(null)).toBeNull();
});

test('nice ticks cover the maximum with clean steps', () => {
  expect(niceTicks(87)).toEqual([0, 25, 50, 75, 100]);
  expect(niceTicks(0)).toEqual([0, 1]);
  const ticks = niceTicks(1234);
  expect(ticks[0]).toBe(0);
  expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(1234);
});
