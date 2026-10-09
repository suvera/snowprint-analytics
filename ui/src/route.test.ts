import { filtersFrom, parseHash, withFilter } from './route';

test('parses site routes with report state', () => {
  const r = parseHash('#/site/example.com?period=30d&f.country=DE&f.page=%2Fblog%2F*');
  expect(r.path).toEqual(['site', 'example.com']);
  expect(r.params.get('period')).toBe('30d');
  expect(filtersFrom(r.params)).toEqual({ country: 'DE', page: '/blog/*' });
});

test('adding and removing filters keeps other state', () => {
  const params = new URLSearchParams('period=7d&f.country=DE');
  const added = withFilter(params, 'source', 'Google');
  expect(filtersFrom(added)).toEqual({ country: 'DE', source: 'Google' });
  expect(added.get('period')).toBe('7d');
  expect(filtersFrom(withFilter(added, 'country', null))).toEqual({ source: 'Google' });
});
