import { useEffect, useRef, useState } from 'react'

/**
 * A draw-or-upload signature box.
 *
 * Extracted from the R1 card so Lampiran C's two signatures (the student's in
 * Bahagian C, the supervisor's in Bahagian D) share one implementation — three
 * copies of a canvas with pointer maths is three places for the same bug.
 *
 * Drawing is offered first because the paper forms expect a signature, not a
 * photograph of one; uploading is the fallback for a machine without a pointer.
 * The canvas is fixed at 480x140 and scaled by CSS, so the coordinates are
 * resolution-independent and the exported PNG is the same size everywhere.
 *
 * `onChange(file, type)` receives a `File` (or null when cleared) and the type
 * the caller should send: `drawn` or `uploaded`.
 */
export default function SignaturePad({ onChange, disabled = false, height = 140 }) {
  const canvasRef = useRef(null)
  const drawingRef = useRef(false)
  const [mode, setMode] = useState('draw')
  const [hasInk, setHasInk] = useState(false)

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas || mode !== 'draw') return
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.strokeStyle = '#0f172a'
    ctx.lineWidth = 2.5
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }, [mode])

  function pos(e) {
    const canvas = canvasRef.current
    const rect = canvas.getBoundingClientRect()
    const point = e.touches?.[0] ?? e
    return {
      x: ((point.clientX - rect.left) / rect.width) * canvas.width,
      y: ((point.clientY - rect.top) / rect.height) * canvas.height,
    }
  }

  function start(e) {
    if (disabled) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    drawingRef.current = true
    ctx.beginPath()
    ctx.moveTo(x, y)
  }

  function move(e) {
    if (!drawingRef.current || disabled) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    ctx.lineTo(x, y)
    ctx.stroke()
    if (!hasInk) setHasInk(true)
  }

  function end() {
    if (!drawingRef.current) return
    drawingRef.current = false
    const canvas = canvasRef.current
    canvas.toBlob((blob) => {
      if (!blob) return
      onChange(new File([blob], 'signature.png', { type: 'image/png' }), 'drawn')
    }, 'image/png')
  }

  function clear() {
    const canvas = canvasRef.current
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    setHasInk(false)
    onChange(null, 'drawn')
  }

  return (
    <div>
      <div className="mb-2 flex items-center gap-4 text-xs">
        <label className="flex items-center gap-1.5">
          <input
            type="radio"
            checked={mode === 'draw'}
            disabled={disabled}
            onChange={() => {
              setMode('draw')
              onChange(null, 'drawn')
            }}
          />
          Draw
        </label>
        <label className="flex items-center gap-1.5">
          <input
            type="radio"
            checked={mode === 'upload'}
            disabled={disabled}
            onChange={() => {
              setMode('upload')
              onChange(null, 'uploaded')
            }}
          />
          Upload image
        </label>
      </div>

      {mode === 'draw' ? (
        <div>
          <canvas
            ref={canvasRef}
            width={480}
            height={height}
            onMouseDown={start}
            onMouseMove={move}
            onMouseUp={end}
            onMouseLeave={end}
            onTouchStart={start}
            onTouchMove={move}
            onTouchEnd={end}
            className={`w-full touch-none rounded-md border border-dashed border-slate-300 bg-white ${
              disabled ? 'opacity-50' : 'cursor-crosshair'
            }`}
          />
          <div className="mt-1 flex items-center justify-between text-xs text-slate-500">
            <span>{hasInk ? 'Signature captured.' : 'Sign in the box above.'}</span>
            <button
              type="button"
              onClick={clear}
              disabled={disabled}
              className="text-brand-700 hover:underline disabled:text-slate-400"
            >
              Clear
            </button>
          </div>
        </div>
      ) : (
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={disabled}
          onChange={(e) => {
            const file = e.target.files?.[0] ?? null
            onChange(file, 'uploaded')
          }}
          className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0
                     file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium
                     hover:file:bg-slate-200"
        />
      )}
    </div>
  )
}
