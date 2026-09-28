// normaliseChapters(), lifted verbatim from the published @scarlett-player/chapters@1.19.1
// dist/index.js (see ../PROVENANCE.md). The function is not exported by the package, and
// it is the code that decides which end a chapter gets, so the builder's output is run
// through it rather than through a copy of its rules.
//
// node normalise.mjs '<chapters JSON>'   prints the resolved chapters as JSON
// (Infinity, the "runs to the end" value, is printed as null).
function normaliseChapters(chapters) {
  const valid = chapters.filter(
    (chapter) => chapter && typeof chapter.time === "number" && Number.isFinite(chapter.time) && chapter.time >= 0
  );
  const sorted = [...valid].sort((a, b) => a.time - b.time);
  return sorted.map((chapter, index) => {
    const next = sorted[index + 1];
    const implicitEnd = next ? next.time : Infinity;
    const explicitEnd = chapter.endTime;
    const endTime = typeof explicitEnd === "number" && Number.isFinite(explicitEnd) && explicitEnd > chapter.time ? Math.min(explicitEnd, implicitEnd) : implicitEnd;
    return { ...chapter, endTime };
  });
}

export { normaliseChapters };

if (process.argv[2] !== undefined) {
  const resolved = normaliseChapters(JSON.parse(process.argv[2]));
  process.stdout.write(JSON.stringify(resolved, (key, value) => (value === Infinity ? null : value)));
}
