# Política de Segurança

## Versões Suportadas

As seguintes versões do **SpechPhone** são atualmente suportadas com atualizações de segurança:

| Versão               | Status de Suporte | Notas                                            |
|----------------------|-------------------|--------------------------------------------------|
| Latest (branch main) | Suportado         | Desenvolvimento ativo, recomendado para produção |
| Commits anteriores   | Limitado          | Correções de segurança sob demanda               |
| Forks e modificações | Não suportado     | Responsabilidade do mantenedor                   |

**Nota**: Este projeto está em **beta** e em desenvolvimento ativo. Recomendamos sempre usar a versão mais recente da
branch main.

## Considerações de Segurança

### Segurança de Rede

O **SpechPhone** implementa um softphone SIP/VoIP com comunicação em tempo real. Esteja ciente das seguintes
considerações de segurança:

#### Modelo de Segurança

- **Sem WebRTC** - SpechPhone não usa WebRTC (sem SRTP/ICE/DTLS)
- **Backend de Mídia** - Áudio transportado via RTP (UDP) no backend
- **Cliente Leve** - O navegador atua como cliente leve via WebSocket (entrega de PCM)
- **Requisitos de Produção** - HTTPS e WSS (WebSocket Seguro) são obrigatórios para ambientes de produção
- **Firewall** - Portas RTP não devem ser expostas à internet pública sem firewall adequado

#### Limitações Atuais

- **RTP não criptografado** - Fluxos de mídia RTP são transmitidos sem criptografia por padrão
- **Sem suporte SRTP** - Secure Real-time Transport Protocol não está implementado
- **Sinalização SIP** - Comunicação SIP via UDP sem criptografia (porta 4000)
- **Autenticação MD5 Digest** - Credenciais SIP usam autenticação MD5 Digest padrão

#### Exposição de Rede

- **Portas UDP** - O servidor requer portas UDP abertas para SIP (padrão 4000) e mídia RTP
- **WebSocket** - Porta configurável (padrão 8080 sem SSL, configurável com SSL)
- **Exposição de IP público** - Em produção, garanta configuração adequada de firewall
- **Vulnerabilidade DDoS** - Sem proteção integrada contra inundação ou limitação de taxa

### SSL/TLS e WebSocket Seguro

#### Geração Automática de Certificados

Para acelerar o desenvolvimento local, o SpechPhone possui um sistema de **geração automática de chaves SSL**:

- Se os arquivos de certificado não forem encontrados na inicialização, o sistema usa OpenSSL para gerar automaticamente
  certificados **self-signed**
- Isso permite uso imediato de HTTPS e WSS (WebSocket Seguro)
- Certificados self-signed são adequados apenas para desenvolvimento

#### Requisitos de Produção

⚠️ **IMPORTANTE**: Para ambientes de produção:

1. **Use certificados válidos** - Obtenha certificados de uma Autoridade Certificadora confiável (Let's Encrypt, etc.)
2. **Configure SSL no interface.json** - Defina `ssl: true` e forneça caminhos para `ssl_cert_file` e `ssl_key_file`
3. **HTTPS obrigatório** - Navegadores modernos exigem contexto seguro para acesso ao microfone
4. **WSS obrigatório** - WebSocket Seguro é necessário para comunicação em produção

### Armazenamento Seguro de Credenciais

#### spechphoneVault

O SpechPhone implementa um sistema de armazenamento criptografado chamado **spechphoneVault**:

- **Criptografia de dados** - Credenciais SIP e configurações sensíveis são criptografadas
- **Chave de criptografia** - Usa chave hexadecimal de 32 bytes via variável de ambiente `SPECH_VAULT_KEY_HEX`
- **Operações atômicas** - Implementa locks de arquivo para operações thread-safe
- **Armazenamento persistente** - Dados criptografados salvos em `devices.vault`

#### Gerenciamento de Credenciais

⚠️ **CRÍTICO**: A variável de ambiente `SPECH_VAULT_KEY_HEX` é essencial para a segurança:

1. **Gere uma chave forte** - Use 64 caracteres hexadecimais (32 bytes)
   ```bash
   openssl rand -hex 32
   ```
2. **Nunca compartilhe a chave** - Não commite a chave no controle de versão
3. **Proteja o arquivo .env** - Mantenha permissões restritas (chmod 600)
4. **Backup seguro** - Faça backup da chave em local seguro; perder a chave significa perder acesso aos dados
   criptografados

### Práticas de Segurança Recomendadas

#### Configuração de Rede

1. **Use VPN ou redes privadas** - Implante em ambientes de rede confiáveis
2. **Configuração de firewall** - Restrinja portas SIP/RTP a intervalos de IP conhecidos
   ```bash
   # Exemplo: permitir apenas rede local
   iptables -A INPUT -p udp --dport 4000 -s 192.168.0.0/16 -j ACCEPT
   iptables -A INPUT -p udp --dport 4000 -j DROP
   ```
3. **Isolamento de rede** - Considere segmentação de rede para tráfego VoIP
4. **Monitoramento** - Implemente logging e monitoramento para atividades suspeitas

#### Configuração do Servidor

1. **Variáveis de ambiente** - Configure todas as variáveis sensíveis via arquivo .env:
    - `SPECH_VAULT_KEY_HEX` - Chave de criptografia do vault (obrigatória)
    - `SIP_PASSWORD` - Senha SIP (se aplicável)
    - `SIP_USERNAME` - Usuário SIP (se aplicável)
    - `SIP_DOMAIN` - Domínio SIP (se aplicável)

2. **Permissões de arquivo** - Proteja arquivos sensíveis:
   ```bash
   chmod 600 .env
   chmod 600 devices.vault
   chmod 600 privkey.pem
   chmod 644 fullchain.pem
   ```

3. **Atualizações** - Mantenha dependências atualizadas:
    - Runtime PHP (pcg729)
    - Swoole
    - OpenSSL
    - Extensões de codec (bcg729, opus, psampler)

#### Segurança de Código

1. **Validação de entrada** - Sempre valide dados de entrada do WebSocket e SIP
2. **Sanitização** - Use `escapeshellarg()` para comandos shell (já implementado)
3. **Logs seguros** - Não registre credenciais ou dados sensíveis em logs
4. **Tratamento de erros** - Não exponha detalhes internos em mensagens de erro para clientes

### Segurança de Dependências

#### Runtime Customizado (pcg729)

- **Fonte confiável** - Baixe apenas do repositório oficial: https://github.com/spechshop/pcg729
- **Verificação** - Verifique checksums quando disponíveis
- **Atualizações** - Monitore atualizações de segurança do runtime

#### Extensões Nativas

- **bcg729** - Codec G.729, licença GPL-3.0
- **opus** - Codec Opus, licença BSD
- **psampler** - Resampling de áudio
- **Swoole** - Framework assíncrono, siga avisos de segurança do Swoole

Mantenha todas as extensões atualizadas para suas versões mais recentes.

## Problemas de Segurança Conhecidos

### Issues Atuais

1. **Comunicação RTP em texto claro** - Todo tráfego RTP é não criptografado
2. **Sinalização SIP não criptografada** - Mensagens SIP transmitidas via UDP sem TLS
3. **Certificados self-signed em desenvolvimento** - Adequados apenas para desenvolvimento local
4. **Sem validação de certificado** - TLS/SRTP não implementados
5. **Apenas IPv4** - IPv6 não suportado, limitando flexibilidade de rede
6. **Autenticação limitada** - Apenas autenticação MD5 Digest suportada

### Melhorias de Segurança Planejadas

- [ ] Implementação de SRTP (Secure RTP)
- [ ] Suporte TLS/SIPS para sinalização criptografada
- [ ] Validação de certificado
- [ ] Mecanismos de autenticação aprimorados
- [ ] Limitação de taxa e proteção contra inundação
- [ ] Suporte IPv6
- [ ] Implementação de recebimento de chamadas (atualmente em desenvolvimento)

## Relatando uma Vulnerabilidade

Se você descobrir uma vulnerabilidade de segurança no **SpechPhone**, siga as práticas de divulgação responsável:

### Como Relatar

1. **NÃO abra uma issue pública** para vulnerabilidades de segurança
2. **Entre em contato com os mantenedores**:
    - Via GitHub Security Advisory (recomendado)
    - Via email no perfil do GitHub
    - Via issue privada se disponível
3. **Forneça informações detalhadas**:
    - Descrição da vulnerabilidade
    - Passos para reproduzir
    - Impacto potencial
    - Versão afetada
    - Correção sugerida (se disponível)
    - Prova de conceito (se aplicável)

### O Que Esperar

- **Resposta inicial**: Dentro de 7 dias do relatório
- **Atualizações de status**: A cada 14 dias até a resolução
- **Cronograma de correção**: Depende da severidade
    - Crítico: 7-14 dias
    - Alto: 14-30 dias
    - Médio: 30-60 dias
    - Baixo: 60-90 dias

### Avaliação de Vulnerabilidade

Avaliaremos vulnerabilidades relatadas com base em:

- **Severidade**: Impacto na confidencialidade, integridade e disponibilidade
- **Explorabilidade**: Facilidade de exploração e complexidade do ataque
- **Escopo**: Versões e configurações afetadas
- **Impacto**: Número de usuários potencialmente afetados

### Processo de Divulgação

1. **Confirmação**: Confirmaremos o recebimento do relatório
2. **Investigação**: Investigaremos e reproduziremos o problema
3. **Desenvolvimento**: Desenvolveremos e testaremos uma correção
4. **Divulgação coordenada**: Trabalharemos com você para divulgação coordenada
5. **Lançamento**: Lançaremos a correção e publicaremos um aviso de segurança
6. **Crédito**: Creditaremos você na divulgação (se desejar)

## Avisos de Segurança

Avisos de segurança serão publicados:

- No repositório GitHub (Security Advisories)
- No arquivo CHANGELOG.md
- Em releases do GitHub com tag de segurança

## Política de Divulgação

- **Período de embargo**: Normalmente 90 dias para correções críticas
- **Divulgação pública**: Após correção estar disponível
- **Crédito ao pesquisador**: Com permissão do relator
- **CVE**: Solicitaremos CVE para vulnerabilidades significativas

## Contato de Segurança

Para questões de segurança urgentes ou sensíveis:

- **GitHub Security Advisory**: https://github.com/spechshop/softphone/security/advisories
- **Email**: Verifique o perfil dos mantenedores no GitHub

## Recursos Adicionais

- [Documentação do Projeto](README.md)
- [Código de Conduta](CODE_OF_CONDUCT.md)
- [Política de Segurança do libspech](libspech/SECURITY.md)
- [Changelog](CHANGELOG.md)

## Agradecimentos

Agradecemos aos pesquisadores de segurança e à comunidade por ajudarem a manter o SpechPhone seguro.

---

**Última atualização**: 2026-01-05  
**Versão da política**: 1.0
