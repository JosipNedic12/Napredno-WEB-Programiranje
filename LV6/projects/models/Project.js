// models/Project.js
const mongoose = require('mongoose');

const TeamMemberSchema = new mongoose.Schema({
  name: { type: String, required: true },
  role: { type: String, default: '' },
  email: { type: String, default: '' }
}, { _id: true });

const ProjectSchema = new mongoose.Schema({
  naziv: { type: String, required: true },            // naziv projekta
  opis: { type: String, default: '' },                // opis projekta
  cijena: { type: Number, default: 0 },               // cijena projekta
  obavljeniPoslovi: { type: String, default: '' },    // obavljeni poslovi
  datumPocetka: { type: Date },                       // datum početka
  datumZavrsetka: { type: Date },                     // datum završetka
  clanoviTima: { type: [TeamMemberSchema], default: [] } // više članova tima
}, { timestamps: true });

module.exports = mongoose.model('Project', ProjectSchema);
