# Registro SIP e Web Push: auditoria e evidências locais

## Achados e mudanças

- O estado de retry ficava somente em `Swoole\Table`; um restart perdia a suspensão de credenciais inválidas. Agora a suspensão persiste em `sip_registration_pauses.json`, indexada por fingerprint e hash da configuração, com modo restrito. Falhas de autenticação permanecem suspensas até a configuração mudar. A exaustão temporária permite uma única sonda automática após 24 horas.
- O retry automático aplica 60 e 120 segundos e suspende após a terceira falha temporária. Respostas SIP com `Retry-After` respeitam o intervalo anunciado; erros permanentes de credencial e rejeições SIP definitivas suspendem a conta.
- O bloqueio de tentativa em andamento era local ao processo. O ponto comum de registro agora adquire lock de arquivo não bloqueante por fingerprint, compartilhado entre workers no mesmo host. `SipRegisterManager` usa o `accountId` no lock local, mantendo AOR compartilhado com Contacts de dispositivos distintos independentes.
- O cliente preserva os desafios `401`/`407` e envia Digest no REGISTER seguinte. O laboratório confere o hash Digest, correlação da transação e limpeza após sucesso/timeout.
- O fluxo Push confundia assinatura local com vínculo confirmado e desativava a assinatura quando o usuário tentava novamente após falha de armazenamento. A interface agora marca esse vínculo como pendente e repete o salvamento. O backend valida endpoints HTTPS, retorna erros explícitos em falhas de armazenamento/remoção e só confirma após gravar a assinatura.
- Falhas de registro continuam fora das rotas de Push. Os eventos legítimos de chamada e mensagem continuam nos pontos de entrega existentes.

## Verificações locais

Execução com o binário pcg729 indicado abaixo:

- 174 arquivos PHP passaram em `php -l`; stubs gerados de extensões foram excluídos porque redeclaram funções já fornecidas pelo runtime.
- 15 arquivos JavaScript passaram em `node --check`.
- 19 scripts de teste distintos foram executados com sucesso; 22 invocações contando repetições dos checks compartilhados entre jobs. Nenhum teste dos jobs obrigatórios foi ignorado.
- O laboratório UDP local passou: challenge Digest e resposta 200 em dois datagramas, resposta final duplicada descartada, timeout com duas retransmissões, rejeição 403 e zero transações pendentes ao terminar.
- Stress local progressivo: 1/1, 10/10, 50/50 e 100/100 registros concluídos. No lote de 100 houve pico de 104 corrotinas, 100 transações pendentes simultâneas, 200 datagramas, zero retransmissões e zero pendências após limpeza; a memória reportou +2 MiB e pico de 8 MiB.
- O teste Node `frontend_push_retry_test.mjs` exercita falha e repetição de vínculo, falha de remoção e falha ao cancelar a assinatura no navegador.

Quatro testes browser-driven existentes não fazem parte dos checks obrigatórios: `frontend_inbound_self_call_test.mjs`, `frontend_offline_push_e2e.mjs`, `frontend_outbound_local_provider_test.mjs` e `frontend_registration_test.mjs`. Eles exigem Chrome/CDP e serviços/contas de desenvolvimento. `frontend_registration_test.mjs` carrega credenciais SIP do `.env`, por isso permanece diagnóstico manual e não é chamado no CI. Nenhum envia tráfego para um servidor SIP público no CI.

## Runtime usado

- Release estável consultada na API: `v1.1.2`.
- Asset exato: `php-xmlwriter`, id `611596663`, Linux x86_64.
- SHA-256: `fb5cab9c4c43f018365bbef09cfd4fe34e72c26b6214451e7297c2601da07a21`.
- PHP `8.5.10` ZTS; Swoole `6.3.0RC1`; bcg729 habilitado; Opus `1.1.1`; psampler `0.6.1`; XMLWriter habilitado.
- O instalador validou `--ri` para as cinco extensões e as classes, métodos e funções usados pelo SpechPhone. Não alterou o PHP do sistema.

## Limites que ainda precisam de execução

Os workflows e badges foram criados, mas ainda não há uma execução GitHub associada a esta alteração enquanto ela não for enviada ao branch do PR. Não se deve interpretar badges sem execução como aprovação. A suíte SIP de carga usa UDP real e loopback no processo Swoole, mas não mede concorrência entre processos/hosts nem faz soak de longa duração. A entrega criptográfica final do Web Push também não é testada contra serviços externos; os testes locais verificam armazenamento, isolamento, falhas e recuperação sem enviar notificações reais.
