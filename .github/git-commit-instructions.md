# Git Commit Message Instructions

Use estas instruções para gerar mensagens de commit claras, técnicas e úteis.

## Objetivo
A mensagem de commit deve permitir que qualquer desenvolvedor entenda:
- **O que mudou**
- **Por que mudou**
- **Quais impactos ou riscos existem**

Evite mensagens genéricas.

---

## Estrutura obrigatória da mensagem

### 1. Título (primeira linha)
- Máximo de **72 caracteres**
- Frase no **imperativo**
- Descrever a mudança principal

**Exemplos corretos:**
- `Refactor RTP worker lifecycle and call cleanup`
- `Fix race condition in UDP media routing`
- `Add Opus transcoding support to MediaChannel`

**Evitar:**
- `update`
- `fix`
- `changes`
- `wip`

---

### 2. Corpo do commit (obrigatório se houver lógica relevante)
Explique **o que foi alterado**, focando em comportamento e arquitetura.

- Cite **componentes afetados** (arquivos, classes, módulos)
- Explique **como o fluxo mudou**
- Seja técnico e objetivo

Use listas quando fizer sentido.

---

### 3. Mudanças relevantes (obrigatório)
Liste alterações importantes que:
- Mudam comportamento
- Afetam performance
- Alteram API, fluxo ou arquitetura
- Podem impactar chamadas em produção

Formato recomendado:
- `- Changed …`
- `- Added …`
- `- Removed …`
- `- Refactored …`

---

### 4. Possíveis impactos ou riscos (obrigatório se existir qualquer risco)
Se houver **qualquer possibilidade de problema**, deve ser citada.

Inclua:
- Edge cases conhecidos
- Dependência de configuração externa
- Possível regressão
- Mudança de timing, performance ou consumo de recursos
- Necessidade de monitoramento após deploy

Formato recomendado:
- `⚠️ Potential impact: …`
- `⚠️ Known limitation: …`

Se **não houver riscos conhecidos**, declare explicitamente:
- `No known side effects at this time.`

---

### 5. Contexto opcional
Se a mudança resolve:
- Bug específico
- Incidente
- Limitação técnica
- Dívida técnica

Explique brevemente o contexto ou motivação.

---

## Tom e estilo
- Profissional e técnico
- Direto
- Sem emojis
- Sem storytelling
- Sem opinião pessoal

---

## Exemplo completo

