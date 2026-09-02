# Relatório da atualização Opus — branch `inbound`

Data da validação: 2026-09-02.

Status: a implementação e os testes locais automatizados estão concluídos. A tarefa completa, segundo o critério solicitado, **ainda não pode ser declarada concluída**, porque a extensão `opusChannel` instalada não oferece controle explícito de FEC/bandwidth do encoder e porque os ensaios externos com PJSIP, Linphone e baresip não puderam ser aprovados neste ambiente. Não houve alteração em `CallMediaBridge.php` nem na submodule `libspech`.

## 1. Arquitetura anterior

O frontend selecionava `OPUS/48000/2`, o SDP publicava essencialmente `rtpmap` e `ptime:20`, e os caminhos inbound/outbound tomavam decisões próprias sobre canais e framing. O uplink enviava sempre `sourceChannels=1`; portanto, uma sessão configurada com dois canais recebia mono e a libspech fazia dual-mono. O answer remoto não determinava integralmente a configuração do `MediaChannel`.

## 2. Causa das limitações

Não existia uma configuração canônica de Opus. `rtpmap` era usado incorretamente como indicação semântica de estéreo, `fmtp` não era construído a partir de capacidade real, o browser descartava o segundo canal e havia acumulação fixa em 20 ms no SpechPhone antes da acumulação já existente na libspech.

## 3. Nova configuração Opus

```json
{
  "profile": "standard",
  "channels": 1,
  "stereo": false,
  "maxPlaybackRate": 24000,
  "maxCaptureRate": 24000,
  "maxAverageBitrate": 32000,
  "fec": true,
  "ptime": 20
}
```

`OpusConfig` normaliza persistência antiga, valida os valores, produz/analisa `fmtp`, calcula a interseção offer/answer e fornece a configuração do membro da libspech. O módulo JS espelha os mesmos defaults e limites para a UI e para o preflight de captura. A mesma classe também preserva os planos L/R ao delegar resampling por plano ao `resampler` já existente da libspech.

## 4. Defaults

Mono, 32 kbps, playback/capture em 24 kHz, FEC negociado e `ptime=20`. Contas antigas que só possuem `OPUS/48000/2` recebem esses valores.

## 5. Presets

| Perfil | Canais | Bandwidth | Bitrate | FEC | ptime |
|---|---:|---:|---:|---:|---:|
| Voz econômica | 1 | 24 kHz | 24 kbps | ligado | 40 ms |
| Voz padrão | 1 | 24 kHz | 32 kbps | ligado | 20 ms |
| Alta qualidade | 1 | 48 kHz | 64 kbps | ligado | 20 ms |
| Estéreo | 2 | 48 kHz | 96 kbps | ligado | 20 ms |

Qualquer ajuste manual muda o perfil para `custom`.

## 6. SDP mono produzido

```sdp
m=audio 58658 RTP/AVP 111 101
a=rtpmap:111 OPUS/48000/2
a=fmtp:111 maxplaybackrate=24000;sprop-maxcapturerate=24000;maxaveragebitrate=32000;useinbandfec=1;stereo=0;sprop-stereo=0
a=rtpmap:101 telephone-event/48000
a=fmtp:101 0-15
a=sendrecv
a=ptime:20
```

## 7. SDP estéreo produzido

```sdp
a=rtpmap:111 OPUS/48000/2
a=fmtp:111 maxplaybackrate=48000;sprop-maxcapturerate=48000;maxaveragebitrate=96000;useinbandfec=1;stereo=1;sprop-stereo=1
a=rtpmap:101 telephone-event/48000
a=fmtp:101 0-15
a=sendrecv
a=ptime:20
```

Mono e estéreo mantêm obrigatoriamente `opus/48000/2`; a semântica de canais está no `fmtp` e no pipeline PCM.

## 8. Negociação de `fmtp`

O parser entrega uma estrutura tipada para `maxplaybackrate`, `sprop-maxcapturerate`, `maxaveragebitrate`, `useinbandfec`, `stereo` e `sprop-stereo`. A política usa a interseção local/remota: estéreo somente quando permitido nos dois lados; bandwidth direcional cruzada; menor bitrate comum; FEC somente quando anunciado nos dois lados; `ptime` remoto somente quando suportado. O answer preserva o PT dinâmico oferecido.

Foram testados: mono→mono, estéreo→estéreo, oferta estéreo/resposta mono, oferta remota mono/local mono e oferta remota estéreo/local mono.

## 9. Configuração efetiva do encoder

O `MediaChannel` recebe canais semânticos, `ptime`, mapper TX/RX e configuração do membro. Depois de `addMember`, `OpusConfig::applyEncoder()` aplica `setBitrate()` ao encoder real e registra `opusEncoderApplied` com o estado medido por `getInfo()`. Isso corrige o comportamento legado da libspech que inicialmente associava `maxplaybackrate` ao bitrate.

## 10. FEC

`useinbandfec=0/1` é analisado e negociado. Porém, a extensão `opusChannel` disponível expõe `setBitrate`, `setVBR`, `setDTX`, `setComplexity` e `setSignalVoice`, mas **não expõe setter de FEC**. Por isso o estado registra separadamente `fecNegotiated` e `fecApplied=false`; nenhum método foi inventado e não se afirma recuperação FEC nos testes de perda.

Essa limitação impede cumprir integralmente “FEC anunciado = encoder explicitamente configurado” sem ampliar a API nativa/libspech.

## 11. Bitrate

Valores aceitos na UI/persistência: 16, 24, 32, 48, 64 e 96 kbps. Todos foram validados no SDP, em `opusChannel::getInfo()` e com produção de payload RTP real não vazio.

## 12. Bandwidth

Valores aceitos: 8, 12, 16, 24 e 48 kHz. O clock RTP permanece 48 kHz. Captura usa a taxa efetiva do `AudioContext`; playback Opus é convertido uma única vez para `maxPlaybackRate` antes do bridge do browser. Para estéreo, os planos são separados, cada plano usa o `resampler` existente e o resultado volta a `L,R,L,R`; isso evita tratar amostras intercaladas como uma linha mono. A extensão não oferece um setter nativo de bandwidth, registrado como `bandwidthControlApplied=false`.

## 13. Captura mono

O browser solicita `channelCount:1`, o worklet produz PCM16 mono, o protocolo marca um canal e o backend chama `sendPcmToLeg('a', pcm, sourceRate, 1)`. O membro Opus permanece com um canal; os testes RTP mono usam esse caminho e não fazem duplicação para estéreo.

## 14. Captura estéreo

O browser solicita `channelCount:2`; o worklet conserva `L,R,L,R...`; o protocolo e a sessão carregam dois canais; o backend chama `sendPcmToLeg(..., 2)`. Quando a captura estéreo não está em 48 kHz, o SpechPhone apenas adapta a taxa por plano com o resampler da libspech antes da injeção, pois o resampling interno da versão atual não preserva planos intercalados. Uma fonte conhecida L=440 Hz/R=880 Hz foi codificada e decodificada. Resultado medido: aproximadamente 491,7 Hz à esquerda e 883,3 Hz à direita, e o PCM decodificado difere de sua versão dual-mono. O caminho estéreo 24→48→24 kHz também preservou as duas frequências.

## 15. Fallback estéreo → mono

Após `getUserMedia`, `MediaStreamTrack.getSettings().channelCount` é conferido. Se não for 2, a configuração efetiva passa a mono antes do offer/answer, o frontend mostra “Estéreo indisponível neste dispositivo — usando mono”, o SDP usa `stereo=0;sprop-stereo=0` e o protocolo usa `sourceChannels=1`. Há teste do resolvedor JS, worklet e SDP de fallback.

## 16. Clock DTMF

Oferta Opus local usa `telephone-event/48000`. Answer respeita PT, clock e `fmtp` remotos; o teste com oferta remota usa PT 110/8 kHz. `codecMapper`, `txCodecMapper`, `rxCodecMapper`, `ptCodecs` e `ptFrequencies` recebem o valor negociado. Eventos `1`, `2` e `#` foram transmitidos em RTP real:

- 48 kHz/PT 101: duração final 7680 ticks;
- 8 kHz/PT 110: duração final 1280 ticks.

## 17. `ptime`

A UI oferece somente 10, 20, 40 e 60 ms. Oferta, answer, configuração efetiva e `MediaChannel::setPacketTime()` usam o mesmo valor. O frame interno do browser é 10 ms para `ptime=10` e 20 ms nos demais casos; se o answer muda o `ptime` efetivo, o frontend recria a fila e reconecta o uplink com o framing correto, evitando dois pacotes RTP de 10 ms em rajada. A libspech continua responsável pela acumulação, codificação, timestamp e packetização RTP.

## 18. Integração com `MediaChannel`

Inbound e outbound configuram o membro com `channels`, `ptime`, `leg`, config Opus e mappers TX/RX. O uplink SpechPhone não possui packetizer Opus próprio: cada frame PCM ritmado vai para `sendPcmToLeg`, incluindo o número de canais reais. A única preparação anterior é o resampling por plano exigido quando PCM estéreo não está a 48 kHz; acumulação, encode, timestamp e RTP continuam integralmente na libspech.

## 19. Timestamp/gap RTP medidos

| ptime | incremento timestamp | gap médio observado |
|---:|---:|---:|
| 10 ms | 480 | 10,75–11,35 ms |
| 20 ms | 960 | 21,47–22,74 ms |
| 40 ms | 1920 | 41,41–44,19 ms |
| 60 ms | 2880 | 61,21–64,43 ms |

As faixas registram execuções locais repetidas; todas ficaram dentro da tolerância automatizada e os payloads foram decodificados com o tamanho PCM esperado.

## 20. Arquivos alterados pela tarefa

- `plugins/Utils/helpers/OpusConfig.php`, `SdpHelper.php`, `OutboundCall.php`, `OutboundMediaSession.php`;
- `plugins/Message/handlers/startCall.php`, `acceptCall.php`, `saveConfig.php`, `connect.php`;
- `plugins/Request/pages/default.html`, `plugins/Request/modules/includes/head.html`;
- `js/opus-config.js`, `js/opus-recorder-worklet.js`, `js/mic-uplink.js`, `js/router.js`;
- `audio.php`, `MicUplinkFrame.php`, `MicUplinkSession.php`, `MicJitterBuffer.php`;
- `tests/OpusSupportTest.php`, `tests/OpusOutboundNegotiationTest.php`, `tests/MicUplinkPipelineTest.php`, `tests/PhoneControllerOutboundTest.php`, `tests/frontend_mic_uplink_test.mjs`;
- este relatório.

`CallMediaBridge.php` e a submodule `libspech` não foram modificados. Existe no working tree uma alteração independente em `plugins/autoload.php`; ela não faz parte desta tarefa.

## 21. Testes unitários

Passaram: `OpusSupportTest.php`, `MicUplinkPipelineTest.php`, `AccountMessagingIsolationTest.php`, `CallStateBindingTest.php`, `HangUpCallBroadcastTest.php`, `IdentityStressTest.php`, `SipMessageBodyTest.php`, `SipRegisterManagerTest.php`, `WebPushStorageTest.php`, além de lint PHP/JS e `git diff --check`.

## 22. Testes E2E

Passaram os testes locais de ciclo SIP/provider (`PhoneControllerOutboundTest.php`), negociação Opus UAC (`OpusOutboundNegotiationTest.php`), lifecycle inbound frontend, mic uplink e worklet estéreo em VM JS. O E2E que depende de navegador real não iniciou porque não havia Chrome/CDP em `127.0.0.1:9223` (`ECONNREFUSED`); portanto, não é contabilizado como aprovado.

## 23. PJSIP

Não executado: `pjsua`/`pjsua2` não estão instalados no ambiente. Pendente teste externo com captura de SDP e RTP.

## 24. Linphone

Não executado: `linphonec`/`linphone-cli` não estão instalados no ambiente. Pendente teste externo com captura de SDP e RTP.

## 25. baresip

O binário baresip 4.10.0 e o módulo `opus.so` estão instalados e o módulo aceitou configuração `opus/48000/2`. A tentativa de chamada SIP isolada falhou antes de enviar o INVITE com `sipsess_connect: Protocol not supported [93]`; portanto, **não é um teste de interoperabilidade aprovado**.

## 26. Rede ruim

O teste usa tamanhos de RTP Opus realmente codificados, gargalo determinístico de 128 kbps, 60 ms de delay, jitter e 5% de perda:

| Perfil | RTP enviados/recebidos | perda | jitter p95 | banda no fio |
|---|---:|---:|---:|---:|
| mono 24k FEC, ptime20 | 100/95 | 5% | 23 ms | 28,8 kbps |
| mono 32k FEC, ptime20 | 100/95 | 5% | 23 ms | 36,8 kbps |
| mono 24k FEC, ptime40 | 50/48 | 4% | 23 ms | 26,4 kbps |
| estéreo 96k, ptime20 | 100/95 | 5% | 23 ms | 100,8 kbps |

Não foi atribuída recuperação a FEC, pois ela não pôde ser ativada explicitamente. Separadamente, `netem_mic_uplink_profiles.sh` passou com delays de 50/100/150/250 ms, jitter e slot 200–500 ms sem destruir o pacing do uplink.

## 27. CPU mono/estéreo

Microbenchmark de encode por um segundo de PCM, nesta máquina:

| modo | 10 ms | 20 ms | 40 ms | 60 ms |
|---|---:|---:|---:|---:|
| mono 32k | 9,54 ms | 5,93 ms | 5,74 ms | 5,22 ms |
| estéreo 96k | 11,87 ms | 11,30 ms | 11,09 ms | 10,42 ms |

São números de microbenchmark, sujeitos a warm-up/escalonamento. Nesta execução, estéreo custou mais CPU em todos os `ptime`; em 20–60 ms consumiu aproximadamente o dobro, além de triplicar o payload configurado.

## 28. Comparação de `ptime`

PPS medido: 100, 50, 25 e 16 para 10, 20, 40 e 60 ms, respectivamente. O teste da libspech `test_media_channel_member_ptime.php` também passou, cobrindo acumulador PCM, RTP, silêncio, DTMF, estado por membro e legado de 20 ms.

## 29. Regressão dos demais codecs

SDP/DTMF/ptime de PCMA, PCMU, G729, GSM e L16 foram verificados sem parâmetros Opus. A suíte completa de `PhoneControllerOutboundTest.php` passou, assim como a validação de ptime/codec da libspech. Nenhuma mudança foi feita na libspech.

## 30. Limitações restantes

1. Falta API nativa em `opusChannel` para aplicar FEC e bandwidth diretamente ao encoder; hoje FEC é capacidade negociada e bandwidth é aplicado no pipeline de captura/playback.
2. Faltam testes externos aprovados com PJSIP, Linphone e baresip.
3. Falta repetir o E2E em navegador/hardware real com CDP disponível, incluindo microfone estéreo e dispositivo mono para fallback.
4. A camada UDP interna ainda usa delimitador textual sobre PCM binário, limitação preexistente que merece migração futura para length-prefix.

Até resolver os itens 1–3, este trabalho não atende literalmente a todos os critérios obrigatórios de conclusão, apesar de o caminho local frontend → SDP → negociação → MediaChannel → opusChannel → RTP estar coberto por testes automatizados.
