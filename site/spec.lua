-- Wraps tables so wide ones scroll inside their own box on small screens,
-- instead of pushing the whole page sideways.
function Table(t)
  return pandoc.Div({ t }, pandoc.Attr('', { 'table-wrap' }))
end
