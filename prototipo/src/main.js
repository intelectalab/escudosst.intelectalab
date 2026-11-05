/* Arranque */
(function iniciar() {
  aplicarTema();
  if (esConsultor()) aplicarEmpresa(S.empresa);
  render();
}());
