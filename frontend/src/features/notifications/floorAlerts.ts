let audio: AudioContext | null = null

export type FloorSoundId = 'chirp' | 'ding' | 'double' | 'chime' | 'kitchen' | 'waiter'

export const FLOOR_SOUND_PREVIEWS: Array<{ id: FloorSoundId; label: string; hint: string }> = [
  { id: 'chirp', label: 'Current chirp', hint: 'What the floor uses now' },
  { id: 'ding', label: 'Single ding', hint: 'One short high beep' },
  { id: 'double', label: 'Double beep', hint: 'Two POS-style beeps' },
  { id: 'chime', label: 'Soft chime', hint: 'Gentler two-note' },
  { id: 'kitchen', label: 'Kitchen once', hint: 'Ding, one time' },
  { id: 'waiter', label: 'Waiter 3 times', hint: 'Chirp, three times' },
]

export async function unlockFloorAlert() {
  try {
    audio = audio ?? new AudioContext()
    await audio.resume()
    return audio.state === 'running'
  } catch {
    return false
  }
}

function tone(context: AudioContext, at: number, frequency: number, duration: number, type: OscillatorType, peak = 0.5) {
  const gain = context.createGain()
  gain.gain.setValueAtTime(0.0001, at)
  gain.gain.exponentialRampToValueAtTime(peak, at + 0.02)
  gain.gain.exponentialRampToValueAtTime(0.0001, at + duration)
  gain.connect(context.destination)
  const oscillator = context.createOscillator()
  oscillator.type = type
  oscillator.frequency.setValueAtTime(frequency, at)
  oscillator.connect(gain)
  oscillator.start(at)
  oscillator.stop(at + duration)
}

function playChirpAt(context: AudioContext, at: number) {
  ;[880, 1175, 988, 1319].forEach((frequency, index) => {
    tone(context, at + index * 0.28, frequency, 0.22, 'square', 0.55)
  })
}

function schedule(context: AudioContext, id: FloorSoundId) {
  const now = context.currentTime
  if (id === 'chirp' || id === 'waiter') {
    const count = id === 'waiter' ? 3 : 1
    for (let index = 0; index < count; index += 1) playChirpAt(context, now + index * 1.6)
    return
  }
  if (id === 'ding' || id === 'kitchen') {
    tone(context, now, 1320, 0.28, 'sine', 0.6)
    return
  }
  if (id === 'double') {
    tone(context, now, 880, 0.14, 'square', 0.5)
    tone(context, now + 0.22, 880, 0.14, 'square', 0.5)
    return
  }
  tone(context, now, 523, 0.32, 'sine', 0.38)
  tone(context, now + 0.18, 784, 0.45, 'sine', 0.42)
}

function vibrateFor(id: FloorSoundId) {
  try {
    if (!('vibrate' in navigator)) return
    if (id === 'waiter') navigator.vibrate([400, 120, 400, 120, 700, 250, 400, 120, 400, 120, 700, 250, 400, 120, 400, 120, 700])
    else if (id === 'double') navigator.vibrate([180, 80, 180])
    else navigator.vibrate([220, 80, 320])
  } catch { /* desktop browsers may throw */ }
}

function startSound(id: FloorSoundId) {
  vibrateFor(id)
  const run = (context: AudioContext) => {
    if (context.state !== 'running') return
    schedule(context, id)
  }
  try {
    audio = audio ?? new AudioContext()
    if (audio.state === 'suspended') {
      void audio.resume().then(() => run(audio!)).catch(() => undefined)
      return
    }
    run(audio)
  } catch { /* autoplay blocked until a tap */ }
}

/** Live kitchen / waiter alerts. Kitchen = 1 chirp, waiter = 3 chirps unless admin changed it. */
export function playFloorAlert(times = 1) {
  startSound(times >= 3 ? 'waiter' : 'chirp')
}

export function playFloorSound(id: FloorSoundId) {
  startSound(id)
}

export type FloorAlertKind = 'kitchen' | 'ready' | 'call' | 'billing'

const SOUND_IDS: FloorSoundId[] = ['chirp', 'ding', 'double', 'chime', 'kitchen', 'waiter']

export function isFloorSoundId(value: unknown): value is FloorSoundId {
  return typeof value === 'string' && SOUND_IDS.includes(value as FloorSoundId)
}

export function soundFor(hotel: { alert_sound_kitchen?: string | null; alert_sound_ready?: string | null; alert_sound_call?: string | null; alert_sound_billing?: string | null } | null | undefined, kind: FloorAlertKind): FloorSoundId {
  const chosen = kind === 'kitchen' ? hotel?.alert_sound_kitchen : kind === 'ready' ? hotel?.alert_sound_ready : kind === 'call' ? hotel?.alert_sound_call : hotel?.alert_sound_billing
  if (isFloorSoundId(chosen)) return chosen
  if (kind === 'kitchen') return 'chirp'
  if (kind === 'billing') return 'double'
  return 'waiter'
}
