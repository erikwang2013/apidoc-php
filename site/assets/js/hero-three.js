import * as THREE from '../vendor/three.module.min.js';

const canvas = document.getElementById('hero-canvas');
const hero = document.querySelector('.hero');

if (canvas && hero) {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isSmall = window.matchMedia('(max-width: 720px)').matches;
  let renderer;

  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      alpha: true,
      antialias: true,
      powerPreference: 'low-power'
    });
  } catch (error) {
    canvas.style.display = 'none';
  }

  if (renderer) {
    renderer.setClearColor(0x000000, 0);
    renderer.outputColorSpace = THREE.SRGBColorSpace;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(52, 1, 0.1, 100);
    camera.position.set(0, 0, 9.5);

    const count = isSmall ? 42 : 76;
    const positions = new Float32Array(count * 3);
    const colors = new Float32Array(count * 3);
    const velocities = [];
    const palette = ['#6c5ce7', '#7ed9a0', '#ff9fba', '#f5c84c'].map((hex) => new THREE.Color(hex));

    for (let i = 0; i < count; i += 1) {
      const offset = i * 3;
      positions[offset] = (Math.random() - 0.5) * 13;
      positions[offset + 1] = (Math.random() - 0.5) * 8.5;
      positions[offset + 2] = (Math.random() - 0.5) * 6;
      velocities.push(new THREE.Vector3(
        (Math.random() - 0.5) * 0.0014,
        (Math.random() - 0.5) * 0.001,
        (Math.random() - 0.5) * 0.0009
      ));
      const color = palette[i % palette.length];
      colors[offset] = color.r;
      colors[offset + 1] = color.g;
      colors[offset + 2] = color.b;
    }

    const pointGeometry = new THREE.BufferGeometry();
    pointGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    pointGeometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

    const points = new THREE.Points(pointGeometry, new THREE.PointsMaterial({
      size: 0.085,
      vertexColors: true,
      transparent: true,
      opacity: 0.9,
      depthWrite: false,
      sizeAttenuation: true
    }));

    const maxPairs = (count * (count - 1)) / 2;
    const linePositions = new Float32Array(maxPairs * 6);
    const lineGeometry = new THREE.BufferGeometry();
    lineGeometry.setAttribute('position', new THREE.BufferAttribute(linePositions, 3));
    lineGeometry.setDrawRange(0, 0);

    const lines = new THREE.LineSegments(lineGeometry, new THREE.LineBasicMaterial({
      color: 0x9d8df0,
      transparent: true,
      opacity: 0.18,
      depthWrite: false
    }));

    const group = new THREE.Group();
    group.add(points, lines);
    scene.add(group);

    const pointer = { x: 0, y: 0 };

    hero.addEventListener('pointermove', (event) => {
      const rect = hero.getBoundingClientRect();
      pointer.x = (event.clientX - rect.left) / rect.width - 0.5;
      pointer.y = (event.clientY - rect.top) / rect.height - 0.5;
    }, { passive: true });

    const updateLines = () => {
      let cursor = 0;
      const position = pointGeometry.attributes.position.array;
      const threshold = isSmall ? 1.9 : 2.1;
      const thresholdSq = threshold * threshold;

      for (let i = 0; i < count; i += 1) {
        const a = i * 3;
        for (let j = i + 1; j < count; j += 1) {
          const b = j * 3;
          const dx = position[a] - position[b];
          const dy = position[a + 1] - position[b + 1];
          const dz = position[a + 2] - position[b + 2];
          if (dx * dx + dy * dy + dz * dz < thresholdSq) {
            linePositions[cursor] = position[a];
            linePositions[cursor + 1] = position[a + 1];
            linePositions[cursor + 2] = position[a + 2];
            linePositions[cursor + 3] = position[b];
            linePositions[cursor + 4] = position[b + 1];
            linePositions[cursor + 5] = position[b + 2];
            cursor += 6;
          }
        }
      }

      lineGeometry.setDrawRange(0, cursor / 3);
      lineGeometry.attributes.position.needsUpdate = true;
    };

    let width = 1;
    let height = 1;
    let visible = true;
    let frame = 0;
    let rotation = 0;
    const clock = new THREE.Clock();

    const resize = () => {
      const rect = hero.getBoundingClientRect();
      width = Math.max(1, Math.round(rect.width));
      height = Math.max(1, Math.round(rect.height));
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, isSmall ? 1.35 : 1.6));
      renderer.setSize(width, height, false);
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
    };

    const render = () => {
      const delta = Math.min(clock.getDelta(), 0.05);
      const position = pointGeometry.attributes.position.array;

      for (let i = 0; i < count; i += 1) {
        const offset = i * 3;
        const velocity = velocities[i];
        position[offset] += velocity.x * delta * 60;
        position[offset + 1] += velocity.y * delta * 60;
        position[offset + 2] += velocity.z * delta * 60;
        if (Math.abs(position[offset]) > 6.5) velocity.x *= -1;
        if (Math.abs(position[offset + 1]) > 4.25) velocity.y *= -1;
        if (Math.abs(position[offset + 2]) > 3) velocity.z *= -1;
      }

      pointGeometry.attributes.position.needsUpdate = true;
      updateLines();
      rotation += 0.0007;
      group.rotation.y = rotation + pointer.x * 0.22;
      group.rotation.x = pointer.y * 0.14;
      renderer.render(scene, camera);

      if (!reduced && visible && !document.hidden) {
        frame = window.requestAnimationFrame(render);
      } else {
        frame = 0;
      }
    };

    const renderOnce = () => {
      renderer.render(scene, camera);
    };

    resize();
    updateLines();

    if (reduced) {
      renderOnce();
    } else {
      render();
    }

    window.addEventListener('resize', () => {
      resize();
      if (reduced || !frame) renderOnce();
    }, { passive: true });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver((entries) => {
        visible = entries[0].isIntersecting;
        if (visible && !reduced && !frame) {
          clock.getDelta();
          render();
        }
      }, { threshold: 0 }).observe(hero);
    }

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && visible && !reduced && !frame) {
        clock.getDelta();
        render();
      }
    });

    canvas.addEventListener('webglcontextlost', (event) => {
      event.preventDefault();
      canvas.style.opacity = '0';
    });
  }
}
